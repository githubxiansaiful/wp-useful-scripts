/**
 * Custom Email Logger for all forms
 * Real-time monitoring of all outgoing emails
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Custom_Email_Log_Snippet {

    private static $current_log_id = null;

    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init' ] );
    }

    public function init() {
        $this->create_table_if_not_exists();

        add_filter( 'wp_mail',           [ $this, 'before_send' ], 1, 1 );
        add_action( 'wp_mail_succeeded', [ $this, 'on_success'  ] );
        add_action( 'wp_mail_failed',    [ $this, 'on_failure'  ] );

        if ( is_admin() ) {
            add_action( 'admin_menu',            [ $this, 'add_admin_menu'  ] );
            add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'  ] );
            add_action( 'admin_init',            [ $this, 'handle_actions'  ] );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  DB                                                                  */
    /* ------------------------------------------------------------------ */

    private function create_table_if_not_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'custom_email_logs';

        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table ) {
            return;
        }

        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            to_email varchar(500) NOT NULL,
            subject varchar(255) NOT NULL,
            message longtext NOT NULL,
            headers text NOT NULL,
            attachments text NULL,
            status varchar(20) DEFAULT 'pending',
            error_message text NULL,
            source text NULL,
            sent_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_sent_at (sent_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ------------------------------------------------------------------ */
    /*  Mail hooks — untouched logic                                        */
    /* ------------------------------------------------------------------ */

    public function before_send( $atts ) {
        global $wpdb;
        $table = $wpdb->prefix . 'custom_email_logs';

        $to = is_array( $atts['to'] ) ? implode( ', ', $atts['to'] ) : $atts['to'];

        $source_parts = [];
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ( $referer ) {
            $parsed    = parse_url( $referer );
            $page_name = basename( $parsed['path'] ?? $referer ) ?: 'Home';
            $source_parts[] = 'Page: ' . $page_name;
        }
        if ( ! empty( $_POST['et_pb_contact_form_id'] ) ) {
            $source_parts[] = 'Divi Form ID: ' . sanitize_text_field( $_POST['et_pb_contact_form_id'] );
        }
        if ( ! empty( $_POST['et_pb_contact_form_submit'] ) ) {
            $source_parts[] = 'Divi Contact Form';
        }
        $source = ! empty( $source_parts ) ? implode( ' | ', $source_parts ) : 'Unknown Source';

        $wpdb->insert( $table, [
            'to_email'    => sanitize_text_field( $to ),
            'subject'     => sanitize_text_field( $atts['subject'] ?? 'No Subject' ),
            'message'     => $atts['message'] ?? '',
            'headers'     => is_array( $atts['headers'] ) ? implode( "\n", $atts['headers'] ) : (string) $atts['headers'],
            'attachments' => ! empty( $atts['attachments'] ) ? json_encode( $atts['attachments'] ) : '',
            'status'      => 'pending',
            'source'      => $source,
        ] );

        self::$current_log_id = $wpdb->insert_id;
        return $atts;
    }

    public function on_success( $atts ) {
        if ( self::$current_log_id ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'custom_email_logs',
                [ 'status' => 'sent' ],
                [ 'id'     => self::$current_log_id ]
            );
            self::$current_log_id = null;
        }
    }

    public function on_failure( $error ) {
        if ( self::$current_log_id ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'custom_email_logs',
                [
                    'status'        => 'failed',
                    'error_message' => is_wp_error( $error ) ? $error->get_error_message() : (string) $error,
                ],
                [ 'id' => self::$current_log_id ]
            );
            self::$current_log_id = null;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Admin Menu                                                          */
    /* ------------------------------------------------------------------ */

    public function add_admin_menu() {
        add_menu_page(
            'Email Logs',
            'Email Logs',
            'manage_options',
            'custom-email-logs',
            [ $this, 'admin_page' ],
            'dashicons-email-alt2',
            25
        );

        add_submenu_page(
            'custom-email-logs',
            'All Logs',
            'All Logs',
            'manage_options',
            'custom-email-logs',
            [ $this, 'admin_page' ]
        );

        add_submenu_page(
            'custom-email-logs',
            'Settings & Tools',
            'Settings & Tools',
            'manage_options',
            'custom-email-logs-settings',
            [ $this, 'settings_page' ]
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Bulk / clear actions                                                */
    /* ------------------------------------------------------------------ */

    public function handle_actions() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Bulk delete
        if (
            isset( $_POST['cel_bulk_action'], $_POST['cel_nonce'] ) &&
            wp_verify_nonce( $_POST['cel_nonce'], 'cel_bulk_action' )
        ) {
            global $wpdb;
            $table = $wpdb->prefix . 'custom_email_logs';
            $action = sanitize_text_field( $_POST['cel_bulk_action'] );

            if ( $action === 'delete_all' ) {
                $wpdb->query( "DELETE FROM $table" );
                wp_redirect( admin_url( 'admin.php?page=custom-email-logs&cel_msg=cleared' ) );
                exit;
            }

            if ( $action === 'delete_failed' ) {
                $wpdb->delete( $table, [ 'status' => 'failed' ] );
                wp_redirect( admin_url( 'admin.php?page=custom-email-logs&cel_msg=failed_cleared' ) );
                exit;
            }

            if ( $action === 'delete_selected' && ! empty( $_POST['cel_ids'] ) ) {
                $ids = array_map( 'intval', (array) $_POST['cel_ids'] );
                $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id IN ($placeholders)", ...$ids ) );
                wp_redirect( admin_url( 'admin.php?page=custom-email-logs&cel_msg=deleted' ) );
                exit;
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Assets                                                              */
    /* ------------------------------------------------------------------ */

    public function enqueue_assets( $hook ) {
        $allowed = [ 'toplevel_page_custom-email-logs', 'email-logs_page_custom-email-logs-settings' ];
        if ( ! in_array( $hook, $allowed, true ) ) return;

        // Google Font
        wp_enqueue_style(
            'cel-google-fonts',
            'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap',
            [],
            null
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Shared CSS & JS                                                     */
    /* ------------------------------------------------------------------ */

    private function print_styles() {
        ?>
        <style>
        /* ── Reset / base ───────────────────────────────────────── */
        #cel-app *,#cel-app *::before,#cel-app *::after{box-sizing:border-box}
        #cel-app{
            --c-bg:       #f4f5f7;
            --c-surface:  #ffffff;
            --c-border:   #e2e4e9;
            --c-text:     #1a1d23;
            --c-muted:    #6b7280;
            --c-sent:     #059669;
            --c-sent-bg:  #ecfdf5;
            --c-failed:   #dc2626;
            --c-failed-bg:#fef2f2;
            --c-pending:  #d97706;
            --c-pending-bg:#fffbeb;
            --c-accent:   #4f46e5;
            --c-accent-bg:#eef2ff;
            --radius:     10px;
            --shadow:     0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.04);
            --shadow-md:  0 4px 12px rgba(0,0,0,.10);
            font-family: 'DM Sans', -apple-system, sans-serif;
            color: var(--c-text);
            background: var(--c-bg);
            padding: 24px 20px 40px;
        }

        /* ── Header ─────────────────────────────────────────────── */
        .cel-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px}
        .cel-title{display:flex;align-items:center;gap:10px;margin:0}
        .cel-title .dashicons{font-size:28px;width:28px;height:28px;color:var(--c-accent)}
        .cel-title h1{font-size:22px;font-weight:600;margin:0;line-height:1}
        .cel-subtitle{font-size:13px;color:var(--c-muted);margin:4px 0 0 38px}

        /* ── Notice ─────────────────────────────────────────────── */
        .cel-notice{
            display:flex;align-items:center;gap:8px;
            background:var(--c-sent-bg);border:1px solid #a7f3d0;
            color:var(--c-sent);border-radius:8px;padding:10px 16px;
            font-size:13px;font-weight:500;margin-bottom:20px;
        }
        .cel-notice.warn{background:var(--c-failed-bg);border-color:#fca5a5;color:var(--c-failed)}

        /* ── Stat cards ──────────────────────────────────────────── */
        .cel-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin-bottom:24px}
        .cel-card{
            background:var(--c-surface);border:1px solid var(--c-border);
            border-radius:var(--radius);padding:18px 20px;
            box-shadow:var(--shadow);position:relative;overflow:hidden;
        }
        .cel-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
        .cel-card.total::before{background:var(--c-accent)}
        .cel-card.sent::before{background:var(--c-sent)}
        .cel-card.failed::before{background:var(--c-failed)}
        .cel-card.pending::before{background:var(--c-pending)}
        .cel-card-label{font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--c-muted);margin-bottom:8px}
        .cel-card-value{font-size:32px;font-weight:600;line-height:1;color:var(--c-text)}
        .cel-card.sent   .cel-card-value{color:var(--c-sent)}
        .cel-card.failed .cel-card-value{color:var(--c-failed)}
        .cel-card.pending .cel-card-value{color:var(--c-pending)}
        .cel-card-sub{font-size:12px;color:var(--c-muted);margin-top:6px}

        /* ── Chart row ───────────────────────────────────────────── */
        .cel-chart-row{
            display:grid;grid-template-columns:1fr auto;gap:16px;
            align-items:center;margin-bottom:24px;
        }
        .cel-chart-wrap{
            background:var(--c-surface);border:1px solid var(--c-border);
            border-radius:var(--radius);padding:20px 24px;
            box-shadow:var(--shadow);display:flex;align-items:center;gap:28px;
            flex-wrap:wrap;
        }
        .cel-donut-svg{flex-shrink:0}
        .cel-legend{display:flex;flex-direction:column;gap:10px;min-width:140px}
        .cel-legend-item{display:flex;align-items:center;gap:8px;font-size:13px}
        .cel-legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
        .cel-legend-name{color:var(--c-muted)}
        .cel-legend-pct{font-weight:600;margin-left:auto}

        /* ── Toolbar ─────────────────────────────────────────────── */
        .cel-toolbar{
            display:flex;align-items:center;gap:8px;flex-wrap:wrap;
            margin-bottom:16px;
        }

        /* unified control height token */
        .cel-toolbar .cel-search,
        .cel-toolbar .cel-select-wrap,
        .cel-toolbar .cel-btn { height:38px; }

        /* search pill */
        .cel-search{
            display:flex;align-items:stretch;flex:1;max-width:360px;
            background:var(--c-surface);border:1px solid var(--c-border);
            border-radius:8px;overflow:hidden;
            box-shadow:var(--shadow);
            transition:border-color .15s, box-shadow .15s;
        }
        .cel-search:focus-within{
            border-color:var(--c-accent);
            box-shadow:0 0 0 3px rgba(79,70,229,.12);
        }
        .cel-search input{
            flex:1;border:none;outline:none;padding:0 12px;
            font-family:inherit;font-size:13px;color:var(--c-text);background:transparent;
            min-width:0;
        }
        .cel-search input::placeholder{color:#a8adb8}
        .cel-search button{
            display:flex;align-items:center;
            background:var(--c-accent);color:#fff;border:none;
            padding:0 16px;cursor:pointer;font-size:13px;font-family:inherit;font-weight:600;
            letter-spacing:.01em;transition:background .15s;white-space:nowrap;flex-shrink:0;
        }
        .cel-search button:hover{background:#4338ca}

        /* custom select wrapper */
        .cel-select-wrap{
            position:relative;display:inline-flex;align-items:center;flex-shrink:0;
        }
        .cel-select-wrap::after{
            content:'';position:absolute;right:11px;top:50%;transform:translateY(-50%);
            width:0;height:0;pointer-events:none;
            border-left:4px solid transparent;
            border-right:4px solid transparent;
            border-top:5px solid var(--c-muted);
        }
        .cel-select{
            height:38px;padding:0 32px 0 12px;
            border-radius:8px;border:1px solid var(--c-border);
            background:var(--c-surface);font-family:inherit;font-size:13px;
            font-weight:500;color:var(--c-text);cursor:pointer;
            box-shadow:var(--shadow);appearance:none;-webkit-appearance:none;
            transition:border-color .15s, box-shadow .15s;
        }
        .cel-select:focus{
            outline:none;border-color:var(--c-accent);
            box-shadow:0 0 0 3px rgba(79,70,229,.12);
        }
        .cel-select:hover{border-color:#b0b6c3}

        /* buttons */
        .cel-btn{
            display:inline-flex;align-items:center;justify-content:center;gap:6px;
            padding:10px 16px;border-radius:8px;font-size:13px;font-weight:600;
            font-family:inherit;cursor:pointer;text-decoration:none;border:1px solid transparent;
            transition:all .15s;white-space:nowrap;letter-spacing:.01em;
        }
        .cel-btn-secondary{
            background:var(--c-surface);border-color:var(--c-border);color:var(--c-text);
            box-shadow:var(--shadow);
        }
        .cel-btn-secondary:hover{background:var(--c-bg);border-color:#b0b6c3;color:var(--c-text)}
        .cel-btn-danger{background:var(--c-failed-bg);border-color:#fca5a5;color:var(--c-failed)}
        .cel-btn-danger:hover{background:#fee2e2}
        .cel-btn-accent{background:var(--c-accent);border-color:var(--c-accent);color:#fff}
        .cel-btn-accent:hover{background:#4338ca}

        /* ── Table ───────────────────────────────────────────────── */
        .cel-table-wrap{
            background:var(--c-surface);border:1px solid var(--c-border);
            border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;
        }
        .cel-table{width:100%;border-collapse:collapse;font-size:13.5px}
        .cel-table thead tr{background:#f9fafb;border-bottom:1.5px solid var(--c-border)}
        .cel-table th{
            padding:11px 14px;text-align:left;font-size:11px;font-weight:600;
            letter-spacing:.06em;text-transform:uppercase;color:var(--c-muted);white-space:nowrap;
        }
        .cel-table td{padding:12px 14px;border-bottom:1px solid var(--c-border);vertical-align:middle}
        .cel-table tbody tr:last-child td{border-bottom:none}
        .cel-table tbody tr:hover td{background:#fafbfc}
        .cel-table input[type=checkbox]{accent-color:var(--c-accent);width:15px;height:15px;cursor:pointer}
        .cel-id{font-family:'DM Mono',monospace;font-size:12px;color:var(--c-muted)}
        .cel-email{font-weight:500}
        .cel-subject{color:var(--c-muted);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .cel-source-cell{max-width:260px;font-size:12px;color:var(--c-muted);line-height:1.5}
        .cel-date{font-family:'DM Mono',monospace;font-size:12px;color:var(--c-muted);white-space:nowrap}

        /* ── Status badges ───────────────────────────────────────── */
        .cel-badge{
            display:inline-flex;align-items:center;gap:5px;
            padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;
            letter-spacing:.04em;text-transform:uppercase;white-space:nowrap;
        }
        .cel-badge::before{content:'';width:6px;height:6px;border-radius:50%}
        .cel-badge.sent   {background:var(--c-sent-bg);   color:var(--c-sent);   }
        .cel-badge.sent::before   {background:var(--c-sent)}
        .cel-badge.failed {background:var(--c-failed-bg); color:var(--c-failed); }
        .cel-badge.failed::before {background:var(--c-failed)}
        .cel-badge.pending{background:var(--c-pending-bg);color:var(--c-pending);}
        .cel-badge.pending::before{background:var(--c-pending)}

        /* ── View button ─────────────────────────────────────────── */
        .cel-view-btn{
            background:var(--c-accent-bg);color:var(--c-accent);
            border:1px solid #c7d2fe;padding:5px 12px;border-radius:6px;
            font-size:12px;font-weight:500;font-family:inherit;cursor:pointer;
            text-decoration:none;transition:all .15s;white-space:nowrap;
        }
        .cel-view-btn:hover{background:var(--c-accent);color:#fff;border-color:var(--c-accent)}

        /* ── Pagination ──────────────────────────────────────────── */
        .cel-pagination{
            display:flex;align-items:center;justify-content:space-between;
            padding:14px 18px;border-top:1px solid var(--c-border);
            background:var(--c-surface);border-radius:0 0 var(--radius) var(--radius);
            flex-wrap:wrap;gap:10px;
        }
        .cel-page-info{font-size:13px;color:var(--c-muted)}
        .cel-page-btns{display:flex;gap:4px;align-items:center}
        .cel-page-btn{
            min-width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;
            border:1px solid var(--c-border);border-radius:6px;background:var(--c-surface);
            font-size:13px;font-weight:500;color:var(--c-text);text-decoration:none;
            transition:all .15s;padding:0 10px;
        }
        .cel-page-btn:hover{background:var(--c-bg);color:var(--c-text)}
        .cel-page-btn.active{background:var(--c-accent);border-color:var(--c-accent);color:#fff}
        .cel-page-btn.disabled{opacity:.4;pointer-events:none}

        /* ── Modal drawer ────────────────────────────────────────── */
        .cel-modal-overlay{
            display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);
            z-index:99999;align-items:flex-start;justify-content:flex-end;
        }
        .cel-modal-overlay.open{display:flex}
        .cel-modal{
            width:min(680px,100vw);height:100vh;background:var(--c-surface);
            box-shadow:-8px 0 32px rgba(0,0,0,.15);display:flex;flex-direction:column;
            animation:celSlideIn .25s ease;overflow:hidden;
        }
        @keyframes celSlideIn{from{transform:translateX(100%)}to{transform:translateX(0)}}
        .cel-modal-header{
            display:flex;align-items:center;justify-content:space-between;
            padding:18px 24px;border-bottom:1px solid var(--c-border);flex-shrink:0;
        }
        .cel-modal-header h2{font-size:16px;font-weight:600;margin:0}
        .cel-modal-close{
            background:none;border:none;cursor:pointer;padding:4px;
            color:var(--c-muted);font-size:20px;line-height:1;border-radius:4px;
        }
        .cel-modal-close:hover{background:var(--c-bg);color:var(--c-text)}
        .cel-modal-body{flex:1;overflow-y:auto;padding:24px}
        .cel-detail-row{display:grid;grid-template-columns:130px 1fr;gap:8px 16px;margin-bottom:20px}
        .cel-detail-label{font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--c-muted);padding-top:2px}
        .cel-detail-value{font-size:13.5px;word-break:break-word}
        .cel-modal-section{margin-top:20px}
        .cel-modal-section h3{font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--c-muted);margin:0 0 10px}
        .cel-code-block{
            background:#1e1f2e;color:#c9d1d9;border-radius:8px;padding:16px;
            font-family:'DM Mono',monospace;font-size:12px;line-height:1.7;
            max-height:320px;overflow:auto;white-space:pre-wrap;word-break:break-word;
        }

        /* ── Settings page ───────────────────────────────────────── */
        .cel-settings-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px;margin-top:24px}
        .cel-settings-card{
            background:var(--c-surface);border:1px solid var(--c-border);
            border-radius:var(--radius);padding:24px;box-shadow:var(--shadow);
        }
        .cel-settings-card h3{font-size:15px;font-weight:600;margin:0 0 6px}
        .cel-settings-card p{font-size:13px;color:var(--c-muted);margin:0 0 18px;line-height:1.6}
        .cel-danger-zone{border-color:#fca5a5!important}
        .cel-danger-zone h3{color:var(--c-failed)}

        /* ── Empty state ─────────────────────────────────────────── */
        .cel-empty{
            text-align:center;padding:60px 20px;color:var(--c-muted);
        }
        .cel-empty .dashicons{font-size:48px;width:48px;height:48px;opacity:.3;display:block;margin:0 auto 16px}
        .cel-empty p{font-size:15px;margin:0}

        /* ── Responsive ──────────────────────────────────────────── */
        @media(max-width:960px){
            .cel-chart-row{grid-template-columns:1fr}
            .cel-table th:nth-child(4),
            .cel-table td:nth-child(4){display:none}
        }
        @media(max-width:700px){
            .cel-table th:nth-child(6),
            .cel-table td:nth-child(6){display:none}
            .cel-cards{grid-template-columns:1fr 1fr}
        }
        </style>
        <?php
    }

    private function print_modal_js() {
        ?>
        <script>
        (function(){
            function openModal(data){
                document.getElementById('cel-modal-overlay').classList.add('open');
                document.getElementById('cel-modal-title').textContent = 'Email Log #' + data.id;
                var b = document.getElementById('cel-modal-body');
                var statusClass = data.status || 'pending';
                var errorRow = data.error_message
                    ? '<div class="cel-detail-label">Error</div><div class="cel-detail-value" style="color:var(--c-failed)">'+esc(data.error_message)+'</div>'
                    : '';
                b.innerHTML =
                    '<div class="cel-detail-row">' +
                      '<div class="cel-detail-label">To</div>' +
                      '<div class="cel-detail-value"><strong>'+esc(data.to_email)+'</strong></div>' +
                      '<div class="cel-detail-label">Subject</div>' +
                      '<div class="cel-detail-value">'+esc(data.subject)+'</div>' +
                      '<div class="cel-detail-label">Status</div>' +
                      '<div class="cel-detail-value"><span class="cel-badge '+statusClass+'">'+statusClass+'</span></div>' +
                      '<div class="cel-detail-label">Source</div>' +
                      '<div class="cel-detail-value" style="font-size:13px;color:var(--c-muted)">'+esc(data.source||'Unknown')+'</div>' +
                      errorRow +
                      '<div class="cel-detail-label">Sent At</div>' +
                      '<div class="cel-detail-value" style="font-family:\'DM Mono\',monospace;font-size:12px">'+esc(data.sent_at)+'</div>' +
                    '</div>' +
                    '<div class="cel-modal-section"><h3>Message Body</h3><div class="cel-code-block">'+esc(data.message)+'</div></div>' +
                    '<div class="cel-modal-section"><h3>Headers</h3><div class="cel-code-block">'+esc(data.headers)+'</div></div>';
            }
            function closeModal(){
                document.getElementById('cel-modal-overlay').classList.remove('open');
            }
            function esc(s){
                return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            }

            document.addEventListener('click', function(e){
                var btn = e.target.closest('[data-cel-view]');
                if(btn){
                    e.preventDefault();
                    try{ openModal(JSON.parse(btn.getAttribute('data-cel-view'))); }catch(err){}
                }
            });
            window.celCloseModal = closeModal;

            document.addEventListener('keydown', function(e){
                if(e.key==='Escape') closeModal();
            });

            // Select-all checkbox
            var selAll = document.getElementById('cel-select-all');
            if(selAll){
                selAll.addEventListener('change', function(){
                    document.querySelectorAll('.cel-row-check').forEach(function(c){c.checked=selAll.checked});
                });
            }
        })();
        </script>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Admin page                                                          */
    /* ------------------------------------------------------------------ */

    public function admin_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'custom_email_logs';

        // Stats
        $count_total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
        $count_sent    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status='sent'" );
        $count_failed  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status='failed'" );
        $count_pending = $count_total - $count_sent - $count_failed;

        // Search + filter
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';

        $conditions = [];
        if ( $search ) {
            $conditions[] = $wpdb->prepare(
                "(to_email LIKE %s OR subject LIKE %s OR source LIKE %s)",
                '%' . $wpdb->esc_like( $search ) . '%',
                '%' . $wpdb->esc_like( $search ) . '%',
                '%' . $wpdb->esc_like( $search ) . '%'
            );
        }
        if ( in_array( $filter, [ 'sent', 'failed', 'pending' ], true ) ) {
            $conditions[] = $wpdb->prepare( "status = %s", $filter );
        }
        $where = $conditions ? 'WHERE ' . implode( ' AND ', $conditions ) : '';

        $per_page     = 20;
        $current_page = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $offset       = ( $current_page - 1 ) * $per_page;
        $total        = (int) $wpdb->get_var( "SELECT COUNT(id) FROM $table $where" );
        $total_pages  = max( 1, (int) ceil( $total / $per_page ) );
        $logs         = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM $table $where ORDER BY sent_at DESC LIMIT %d OFFSET %d", $per_page, $offset ),
            ARRAY_A
        );

        $this->print_styles();

        $sent_pct    = $count_total ? round( $count_sent    / $count_total * 100 ) : 0;
        $failed_pct  = $count_total ? round( $count_failed  / $count_total * 100 ) : 0;
        $pending_pct = 100 - $sent_pct - $failed_pct;

        // Donut SVG maths
        $r = 54; $cx = 64; $cy = 64; $circ = 2 * M_PI * $r;
        function cel_arc( $pct, $offset, $color, $r, $cx, $cy, $circ ) {
            $dash = $circ * $pct / 100;
            $gap  = $circ - $dash;
            return sprintf(
                '<circle cx="%d" cy="%d" r="%d" fill="none" stroke="%s" stroke-width="14" stroke-dasharray="%.2f %.2f" stroke-dashoffset="%.2f" stroke-linecap="round"/>',
                $cx, $cy, $r, $color, $dash, $gap, -$offset * $circ / 100
            );
        }
        ?>
        <div id="cel-app">

        <?php $this->print_styles(); /* already printed above, but needed for subpage — skip second call */ ?>

        <!-- Header -->
        <div class="cel-header">
            <div>
                <div class="cel-title">
                    <span class="dashicons dashicons-email-alt2"></span>
                    <h1>Email Logs</h1>
                </div>
                <p class="cel-subtitle">Real-time monitoring of all outgoing emails</p>
            </div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=custom-email-logs-settings' ) ); ?>" class="cel-btn cel-btn-secondary">
                <span class="dashicons dashicons-admin-settings" style="font-size:16px;width:16px;height:16px;margin-top:1px"></span>
                Settings &amp; Tools
            </a>
        </div>

        <?php if ( isset( $_GET['cel_msg'] ) ) : ?>
        <div class="cel-notice <?php echo $_GET['cel_msg'] === 'deleted' || $_GET['cel_msg'] === 'cleared' || $_GET['cel_msg'] === 'failed_cleared' ? '' : 'warn'; ?>">
            <span class="dashicons dashicons-yes-alt"></span>
            <?php
            $msgs = [
                'cleared'        => 'All logs have been deleted.',
                'failed_cleared' => 'All failed logs have been deleted.',
                'deleted'        => 'Selected logs have been deleted.',
            ];
            echo esc_html( $msgs[ $_GET['cel_msg'] ] ?? 'Action completed.' );
            ?>
        </div>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="cel-cards">
            <div class="cel-card total">
                <div class="cel-card-label">Total Emails</div>
                <div class="cel-card-value"><?php echo esc_html( $count_total ); ?></div>
                <div class="cel-card-sub">All time</div>
            </div>
            <div class="cel-card sent">
                <div class="cel-card-label">Sent</div>
                <div class="cel-card-value"><?php echo esc_html( $count_sent ); ?></div>
                <div class="cel-card-sub"><?php echo $sent_pct; ?>% success rate</div>
            </div>
            <div class="cel-card failed">
                <div class="cel-card-label">Failed</div>
                <div class="cel-card-value"><?php echo esc_html( $count_failed ); ?></div>
                <div class="cel-card-sub"><?php echo $failed_pct; ?>% failure rate</div>
            </div>
            <div class="cel-card pending">
                <div class="cel-card-label">Pending</div>
                <div class="cel-card-value"><?php echo esc_html( $count_pending ); ?></div>
                <div class="cel-card-sub">Awaiting delivery</div>
            </div>
        </div>

        <!-- Donut Chart -->
        <?php if ( $count_total > 0 ) : ?>
        <div class="cel-chart-wrap" style="margin-bottom:24px">
            <svg class="cel-donut-svg" width="128" height="128" viewBox="0 0 128 128">
                <circle cx="64" cy="64" r="54" fill="none" stroke="#f1f2f4" stroke-width="14"/>
                <?php
                echo cel_arc( $sent_pct,   0,                           '#059669', $r, $cx, $cy, $circ );
                echo cel_arc( $failed_pct, $sent_pct,                   '#dc2626', $r, $cx, $cy, $circ );
                echo cel_arc( $pending_pct,$sent_pct + $failed_pct,     '#d97706', $r, $cx, $cy, $circ );
                ?>
                <text x="64" y="60" text-anchor="middle" font-family="DM Sans,sans-serif" font-size="20" font-weight="600" fill="#1a1d23"><?php echo $sent_pct; ?>%</text>
                <text x="64" y="77" text-anchor="middle" font-family="DM Sans,sans-serif" font-size="10" fill="#6b7280">success</text>
            </svg>
            <div class="cel-legend">
                <div class="cel-legend-item">
                    <span class="cel-legend-dot" style="background:#059669"></span>
                    <span class="cel-legend-name">Sent</span>
                    <span class="cel-legend-pct" style="color:#059669"><?php echo $sent_pct; ?>%</span>
                </div>
                <div class="cel-legend-item">
                    <span class="cel-legend-dot" style="background:#dc2626"></span>
                    <span class="cel-legend-name">Failed</span>
                    <span class="cel-legend-pct" style="color:#dc2626"><?php echo $failed_pct; ?>%</span>
                </div>
                <div class="cel-legend-item">
                    <span class="cel-legend-dot" style="background:#d97706"></span>
                    <span class="cel-legend-name">Pending</span>
                    <span class="cel-legend-pct" style="color:#d97706"><?php echo $pending_pct; ?>%</span>
                </div>
            </div>
            <div style="flex:1;min-width:120px">
                <div style="font-size:12px;color:var(--c-muted);margin-bottom:6px">Showing <?php echo esc_html( $total ); ?> result<?php echo $total !== 1 ? 's' : ''; ?></div>
                <div style="font-size:12px;color:var(--c-muted)">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Toolbar: search + filter (GET) -->
        <div class="cel-toolbar">
            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:contents">
                <input type="hidden" name="page" value="custom-email-logs">
                <div class="cel-search">
                    <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search email, subject, page…">
                    <button type="submit">Search</button>
                </div>
                <div class="cel-select-wrap">
                    <select name="status" class="cel-select" onchange="this.form.submit()">
                        <option value="" <?php selected( $filter, '' ); ?>>All Statuses</option>
                        <option value="sent"    <?php selected( $filter, 'sent' ); ?>>Sent</option>
                        <option value="failed"  <?php selected( $filter, 'failed' ); ?>>Failed</option>
                        <option value="pending" <?php selected( $filter, 'pending' ); ?>>Pending</option>
                    </select>
                </div>
            </form>

            <!-- Bulk action (POST) — separate form, just the controls -->
            <div class="cel-select-wrap">
                <select name="cel_bulk_action" form="cel-bulk-rows-form" class="cel-select">
                    <option value="">Bulk Actions</option>
                    <option value="delete_selected">Delete Selected</option>
                </select>
            </div>
            <button type="submit" form="cel-bulk-rows-form" class="cel-btn cel-btn-secondary">Apply</button>
        </div>

        <!-- Table (wrapped in its own bulk POST form for checkboxes) -->
        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" id="cel-bulk-rows-form">
        <?php wp_nonce_field( 'cel_bulk_action', 'cel_nonce' ); ?>
        <input type="hidden" name="page" value="custom-email-logs">
        <div class="cel-table-wrap">
        <?php if ( empty( $logs ) ) : ?>
            <div class="cel-empty">
                <span class="dashicons dashicons-email-alt"></span>
                <p>No logs found<?php echo $search ? ' for "' . esc_html( $search ) . '"' : ''; ?>.</p>
            </div>
        <?php else : ?>
            <table class="cel-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="cel-select-all"></th>
                        <th>ID</th>
                        <th>To</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Source / Page</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $logs as $log ) :
                    $json = esc_attr( json_encode( [
                        'id'            => $log['id'],
                        'to_email'      => $log['to_email'],
                        'subject'       => $log['subject'],
                        'status'        => $log['status'],
                        'source'        => $log['source'],
                        'error_message' => $log['error_message'],
                        'sent_at'       => $log['sent_at'],
                        'message'       => $log['message'],
                        'headers'       => $log['headers'],
                    ] ) );
                    $st = $log['status'] ?? 'pending';
                ?>
                    <tr>
                        <td><input type="checkbox" class="cel-row-check" name="cel_ids[]" value="<?php echo esc_attr( $log['id'] ); ?>"></td>
                        <td class="cel-id">#<?php echo esc_html( $log['id'] ); ?></td>
                        <td class="cel-email"><?php echo esc_html( $log['to_email'] ); ?></td>
                        <td class="cel-subject" title="<?php echo esc_attr( $log['subject'] ); ?>"><?php echo esc_html( $log['subject'] ); ?></td>
                        <td><span class="cel-badge <?php echo esc_attr( $st ); ?>"><?php echo esc_html( $st ); ?></span></td>
                        <td class="cel-source-cell"><?php echo nl2br( esc_html( $log['source'] ?: 'Unknown' ) ); ?></td>
                        <td class="cel-date"><?php echo esc_html( $log['sent_at'] ); ?></td>
                        <td><button type="button" class="cel-view-btn" data-cel-view="<?php echo $json; ?>">View</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="cel-pagination">
                <span class="cel-page-info">
                    <?php
                    $from = $offset + 1;
                    $to   = min( $offset + $per_page, $total );
                    echo "Showing $from–$to of $total";
                    ?>
                </span>
                <div class="cel-page-btns">
                    <?php
                    $base_url = admin_url( 'admin.php?page=custom-email-logs' );
                    if ( $search ) $base_url .= '&s=' . urlencode( $search );
                    if ( $filter ) $base_url .= '&status=' . urlencode( $filter );

                    echo '<a href="' . esc_url( $base_url . '&paged=1' ) . '" class="cel-page-btn' . ( $current_page === 1 ? ' disabled' : '' ) . '">«</a>';
                    echo '<a href="' . esc_url( $base_url . '&paged=' . max( 1, $current_page - 1 ) ) . '" class="cel-page-btn' . ( $current_page === 1 ? ' disabled' : '' ) . '">‹</a>';

                    $start = max( 1, $current_page - 2 );
                    $end   = min( $total_pages, $current_page + 2 );
                    for ( $i = $start; $i <= $end; $i++ ) {
                        echo '<a href="' . esc_url( $base_url . '&paged=' . $i ) . '" class="cel-page-btn' . ( $i === $current_page ? ' active' : '' ) . '">' . $i . '</a>';
                    }

                    echo '<a href="' . esc_url( $base_url . '&paged=' . min( $total_pages, $current_page + 1 ) ) . '" class="cel-page-btn' . ( $current_page === $total_pages ? ' disabled' : '' ) . '">›</a>';
                    echo '<a href="' . esc_url( $base_url . '&paged=' . $total_pages ) . '" class="cel-page-btn' . ( $current_page === $total_pages ? ' disabled' : '' ) . '">»</a>';
                    ?>
                </div>
            </div>
        <?php endif; ?>
        </div><!-- /.cel-table-wrap -->
        </form><!-- /#cel-bulk-rows-form -->

        <!-- Modal / Drawer -->
        <div class="cel-modal-overlay" id="cel-modal-overlay" onclick="if(event.target===this)celCloseModal()">
            <div class="cel-modal">
                <div class="cel-modal-header">
                    <h2 id="cel-modal-title">Email Log</h2>
                    <button class="cel-modal-close" onclick="celCloseModal()">✕</button>
                </div>
                <div class="cel-modal-body" id="cel-modal-body"></div>
            </div>
        </div>

        </div><!-- /#cel-app -->
        <?php $this->print_modal_js(); ?>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Settings sub-page                                                   */
    /* ------------------------------------------------------------------ */

    public function settings_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'custom_email_logs';
        $size  = $wpdb->get_var( $wpdb->prepare(
            "SELECT ROUND((data_length + index_length) / 1024, 2) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME, $table
        ) );

        $this->print_styles();
        ?>
        <div id="cel-app">
        <div class="cel-header">
            <div>
                <div class="cel-title">
                    <span class="dashicons dashicons-admin-settings"></span>
                    <h1>Settings &amp; Tools</h1>
                </div>
                <p class="cel-subtitle">Manage and maintain your email log database</p>
            </div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=custom-email-logs' ) ); ?>" class="cel-btn cel-btn-secondary">
                ← Back to Logs
            </a>
        </div>

        <?php if ( isset( $_GET['cel_msg'] ) ) : ?>
        <div class="cel-notice">
            <span class="dashicons dashicons-yes-alt"></span>
            <?php
            $msgs = [
                'cleared'        => 'All logs have been deleted.',
                'failed_cleared' => 'All failed logs have been deleted.',
            ];
            echo esc_html( $msgs[ $_GET['cel_msg'] ] ?? 'Done.' );
            ?>
        </div>
        <?php endif; ?>

        <div class="cel-settings-grid">
            <!-- DB Info -->
            <div class="cel-settings-card">
                <h3>Database Info</h3>
                <p>Current size of the log table on disk.</p>
                <div style="font-size:28px;font-weight:600;color:var(--c-accent)">
                    <?php echo esc_html( $size ?: '—' ); ?> KB
                </div>
                <div style="font-size:12px;color:var(--c-muted);margin-top:4px">Table: <?php echo esc_html( $table ); ?></div>
            </div>

            <!-- Clear Failed -->
            <div class="cel-settings-card cel-danger-zone">
                <h3>Clear Failed Logs</h3>
                <p>Delete all logs with a <strong>Failed</strong> status. This cannot be undone.</p>
                <form method="post" onsubmit="return confirm('Delete all failed logs?')">
                    <?php wp_nonce_field( 'cel_bulk_action', 'cel_nonce' ); ?>
                    <input type="hidden" name="page" value="custom-email-logs">
                    <input type="hidden" name="cel_bulk_action" value="delete_failed">
                    <button type="submit" class="cel-btn cel-btn-danger">Delete Failed Logs</button>
                </form>
            </div>

            <!-- Clear All -->
            <div class="cel-settings-card cel-danger-zone">
                <h3>Clear All Logs</h3>
                <p>Permanently delete <strong>every</strong> log entry. Use with caution.</p>
                <form method="post" onsubmit="return confirm('Are you sure? This will delete ALL logs and cannot be undone.')">
                    <?php wp_nonce_field( 'cel_bulk_action', 'cel_nonce' ); ?>
                    <input type="hidden" name="page" value="custom-email-logs">
                    <input type="hidden" name="cel_bulk_action" value="delete_all">
                    <button type="submit" class="cel-btn cel-btn-danger">Delete All Logs</button>
                </form>
            </div>

            <!-- About -->
            <div class="cel-settings-card">
                <h3>About</h3>
                <p>Custom Email Logger intercepts all <code>wp_mail()</code> calls and records them with source detection for Divi forms.</p>
                <div style="font-size:12px;color:var(--c-muted)">
                    Hooks used: <code>wp_mail</code>, <code>wp_mail_succeeded</code>, <code>wp_mail_failed</code>
                </div>
                <p style="margin-top: 10px;">Made by Xian Saiful</p>
            </div>
        </div>
        </div>
        <?php
    }
}

// Initialize
new Custom_Email_Log_Snippet();