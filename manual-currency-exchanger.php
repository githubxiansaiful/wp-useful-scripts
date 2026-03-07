/* ================================
   MANUAL WOOCOMMERCE CURRENCY SWITCHER
   ================================ */

// Start PHP session
add_action('init', function () {
    if (!session_id()) session_start();
});


// --------------------
// Currency selector shortcode
// Use: [manual_currency_switcher]
// --------------------
add_shortcode('manual_currency_switcher', function () {

    $current = isset($_SESSION['manual_currency']) ? $_SESSION['manual_currency'] : 'USD';

    $currencies = [
        'USD' => 'USD ($)',
        'EUR' => 'EUR (€)',
    ];

    $html = '<form method="post" style="display:inline-block;">
    <select name="manual_currency" onchange="this.form.submit()">';

    foreach ($currencies as $code => $label) {
        $selected = ($current == $code) ? 'selected' : '';
        $html .= "<option value='{$code}' {$selected}>{$label}</option>";
    }

    $html .= '</select></form>';

    return $html;
});


// Save selection
add_action('init', function () {
    if (isset($_POST['manual_currency'])) {
        $_SESSION['manual_currency'] = sanitize_text_field($_POST['manual_currency']);
    }
});


// --------------------
// Currency rates
// Base currency must be USD
// --------------------
function manual_currency_rates() {
    return [
        'USD' => 1,
        'EUR' => 0.92,
    ];
}


// Current currency
function manual_current_currency(){
    return isset($_SESSION['manual_currency']) ? $_SESSION['manual_currency'] : 'USD';
}


// Convert helper
function manual_convert_value($price){

    if($price === '' || $price === null) return $price;

    $currency = manual_current_currency();
    $rates = manual_currency_rates();

    if(isset($rates[$currency])){
        $price = floatval($price) * floatval($rates[$currency]);
    }

    return $price;
}


// --------------------
// Convert ALL product prices
// --------------------
add_filter('woocommerce_product_get_price', fn($p)=>manual_convert_value($p), 99);
add_filter('woocommerce_product_get_regular_price', fn($p)=>manual_convert_value($p), 99);
add_filter('woocommerce_product_get_sale_price', fn($p)=>manual_convert_value($p), 99);

add_filter('woocommerce_variation_prices_price', fn($p)=>manual_convert_value($p), 99);
add_filter('woocommerce_variation_prices_regular_price', fn($p)=>manual_convert_value($p), 99);
add_filter('woocommerce_variation_prices_sale_price', fn($p)=>manual_convert_value($p), 99);


// --------------------
// Convert variation AJAX JSON prices
// (fixes variable product dropdown price)
// --------------------
add_filter('woocommerce_available_variation', function($variation){

    foreach(['display_price','display_regular_price','price','regular_price','sale_price'] as $key){
        if(isset($variation[$key])){
            $variation[$key] = manual_convert_value($variation[$key]);
        }
    }

    return $variation;

}, 99);


// --------------------
// Convert cart + checkout totals
// --------------------
add_action('woocommerce_before_calculate_totals', function($cart){

    if(is_admin() && !defined('DOING_AJAX')) return;

    foreach($cart->get_cart() as $item){

        $base_price = $item['data']->get_meta('_original_price');

        // store original once
        if(!$base_price){
            $base_price = $item['data']->get_price('edit');
            $item['data']->update_meta_data('_original_price', $base_price);
        }

        $item['data']->set_price( manual_convert_value($base_price) );
    }

}, 99);


// --------------------
// Change currency symbol
// --------------------
add_filter('woocommerce_currency_symbol', function ($symbol){

    switch(manual_current_currency()){
        case 'EUR': return '€';
        case 'USD': return '$';
    }

    return $symbol;

}, 99);


// --------------------
// FINAL PRICE conversion layer (Divi / cached HTML fix)
// --------------------
add_filter('raw_woocommerce_price', function($price){
    return manual_convert_value($price);
}, 99);


// --------------------
// Disable WooCommerce SALE system globally
// --------------------
add_filter('woocommerce_product_is_on_sale', '__return_false', 999);

add_filter('woocommerce_get_price_html', function($price, $product){
    return wc_price( $product->get_price() );
}, 999, 2);