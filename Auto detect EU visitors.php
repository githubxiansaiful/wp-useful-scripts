/* ================================
   SMART WOOCOMMERCE CURRENCY SWITCHER
   ================================ */


// --------------------
// Detect visitor currency
// --------------------
function manual_detect_currency(){

    // If user selected currency manually
    if(isset($_COOKIE['manual_currency'])){
        return sanitize_text_field($_COOKIE['manual_currency']);
    }

    // WooCommerce geolocation
    $location = WC_Geolocation::geolocate_ip();
    $country  = $location['country'];

    // EU countries list
    $eu_countries = [
        'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE',
        'GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT',
        'RO','SK','SI','ES','SE'
    ];

    if(in_array($country, $eu_countries)){
        return 'EUR';
    }

    return 'USD';
}


// --------------------
// Currency selector shortcode
// --------------------
add_shortcode('manual_currency_switcher', function () {

    $current = manual_detect_currency();

    $currencies = [
        'USD' => 'USD ($)',
        'EUR' => 'EUR (€)',
    ];

    $html = '<form method="post">
    <select name="manual_currency" onchange="this.form.submit()">';

    foreach ($currencies as $code => $label) {
        $selected = ($current == $code) ? 'selected' : '';
        $html .= "<option value='{$code}' {$selected}>{$label}</option>";
    }

    $html .= '</select></form>';

    return $html;
});


// --------------------
// Save user currency choice
// --------------------
add_action('init', function () {

    if(isset($_POST['manual_currency'])){

        setcookie(
            'manual_currency',
            sanitize_text_field($_POST['manual_currency']),
            time() + (30 * DAY_IN_SECONDS),
            COOKIEPATH,
            COOKIE_DOMAIN
        );

        $_COOKIE['manual_currency'] = sanitize_text_field($_POST['manual_currency']);
    }

});


// --------------------
// Currency rates
// --------------------
function manual_currency_rates() {

    return [
        'USD' => 1,
        'EUR' => 0.92,
    ];

}


// --------------------
// Current currency
// --------------------
function manual_current_currency(){

    return manual_detect_currency();

}


// --------------------
// Convert helper
// --------------------
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
// Convert product prices
// --------------------
add_filter('woocommerce_product_get_price', fn($p)=>manual_convert_value($p), 99);
add_filter('woocommerce_product_get_regular_price', fn($p)=>manual_convert_value($p), 99);
add_filter('woocommerce_product_get_sale_price', fn($p)=>manual_convert_value($p), 99);

add_filter('woocommerce_variation_prices_price', fn($p)=>manual_convert_value($p), 99);
add_filter('woocommerce_variation_prices_regular_price', fn($p)=>manual_convert_value($p), 99);
add_filter('woocommerce_variation_prices_sale_price', fn($p)=>manual_convert_value($p), 99);


// --------------------
// Fix variation AJAX prices
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
// Convert cart prices
// --------------------
add_action('woocommerce_before_calculate_totals', function($cart){

    if(is_admin() && !defined('DOING_AJAX')) return;

    foreach($cart->get_cart() as $item){

        $base_price = $item['data']->get_meta('_original_price');

        if(!$base_price){
            $base_price = $item['data']->get_price('edit');
            $item['data']->update_meta_data('_original_price', $base_price);
        }

        $item['data']->set_price( manual_convert_value($base_price) );

    }

}, 99);


// --------------------
// Currency symbol
// --------------------
add_filter('woocommerce_currency_symbol', function ($symbol){

    switch(manual_current_currency()){

        case 'EUR':
            return '€';

        case 'USD':
            return '$';

    }

    return $symbol;

}, 99);


// --------------------
// Fix cached HTML
// --------------------
add_filter('raw_woocommerce_price', function($price){

    return manual_convert_value($price);

}, 99);


// --------------------
// Disable WooCommerce SALE badges
// --------------------
add_filter('woocommerce_product_is_on_sale', '__return_false', 999);

add_filter('woocommerce_get_price_html', function($price, $product){

    return wc_price( $product->get_price() );

}, 999, 2);