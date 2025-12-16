<?php
/**
 * Plugin Name: ERP Sync Full
 * Description: Full sync with WooCommerce: Categories, Products + Delivery Area Selection
 * Version: 4.0
 * Author: Khaled
 */

if (!defined('ABSPATH')) exit;

// -------------------------
// Admin Menu & Settings
// -------------------------
add_action('admin_menu', function () {
    add_menu_page('ERP Sync Settings','ERP Sync','manage_options','erp-sync-settings','erp_sync_settings_page','dashicons-update',56);
});

add_action('admin_init', function(){
    register_setting('erp_sync_settings','erp_client_id');
    register_setting('erp_sync_settings','erp_username');
    register_setting('erp_sync_settings','erp_password');
});

// -------------------------
// Admin UI
// -------------------------
function erp_sync_settings_page(){
    ?>
    <div class="wrap">
        <h1>ERP Full Sync</h1>

        <form method="post" action="options.php">
            <?php settings_fields('erp_sync_settings'); ?>
            <table class="form-table">
                <tr><th>Client ID</th><td><input type="text" name="erp_client_id" value="<?php echo esc_attr(get_option('erp_client_id')); ?>" class="regular-text"></td></tr>
                <tr><th>Username</th><td><input type="text" name="erp_username" value="<?php echo esc_attr(get_option('erp_username')); ?>" class="regular-text"></td></tr>
                <tr><th>Password</th><td><input type="password" name="erp_password" value="<?php echo esc_attr(get_option('erp_password')); ?>" class="regular-text"></td></tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <form method="post">
            <input type="hidden" name="erp_action" value="sync_full">
            <?php submit_button('Sync Full'); ?>
        </form>

        <h2>Debug Logs</h2>
        <div style="background:#fff;padding:10px;max-height:400px;overflow:auto;border:1px solid #ccc;">
            <?php echo nl2br(get_option('erp_debug_log','No logs yet')); ?>
        </div>
    </div>

    <?php
    if(isset($_POST['erp_action']) && $_POST['erp_action']=='sync_full'){
        $sync = new ERP_Woo_Sync();
        $sync->sync_categories();
        $sync->sync_products();
        $sync->save_log();
    }
}

// -------------------------
// ERP Sync Class (Unmodified)
// -------------------------
class ERP_Woo_Sync {
    private $client;
    private $user;
    private $pass;
    private $log = [];
    private $wpdb;

    function __construct(){
        global $wpdb;
        $this->wpdb   = $wpdb;
        $this->client = get_option('erp_client_id');
        $this->user   = get_option('erp_username');
        $this->pass   = get_option('erp_password');
    }

    private function api_post($endpoint, $body){
        $resp = wp_remote_post(
            "https://nzstudio.elnozom.me/api/WooCommerce/".$endpoint,
            [
                'body'=>json_encode($body),
                'headers'=>['Content-Type'=>'application/json'],
                'timeout'=>30
            ]
        );
        if(is_wp_error($resp)){
            $this->log[]="[ERROR] ".$resp->get_error_message();
            return false;
        }
        return json_decode(wp_remote_retrieve_body($resp),true);
    }

    private function notify_update($table, $erp_id, $item_id, $woo_id, $sync=1, $cat_id=0, $woo_cat_id=0){
        $body = [
            "tableType"=>$table,
            "id"=>$erp_id,
            "itemId"=>$item_id,
            "wooItemId"=>$woo_id,
            "syncStatus"=>$sync,
            "catId"=>$cat_id,
            "wooCatId"=>$woo_cat_id,
            "catName"=>"",
            "storeCode"=>0,
            "searchText"=>"",
            "rqm"=>[
                "clientCode"=>$this->client,
                "userName"=>$this->user,
                "password"=>$this->pass,
                "encrypted"=>0,
                "compressed"=>0,
                "sessionId"=>0
            ]
        ];
        $this->api_post("WooSyncUpdate",$body);
        $this->log[]="[NOTIFY UPDATE] {$table} ERP:$erp_id → Woo:$woo_id Sync:$sync";
    }

    private function notify_delete($table,$erp_id,$item_id){
        $body = [
            "tableType"=>$table,"id"=>$erp_id,"itemId"=>$item_id,
            "wooItemId"=>0,"syncStatus"=>3,"catId"=>0,"wooCatId"=>0,"catName"=>"","storeCode"=>0,"searchText"=>"",
            "rqm"=>[
                "clientCode"=>$this->client,
                "userName"=>$this->user,
                "password"=>$this->pass,
                "encrypted"=>0,"compressed"=>0,"sessionId"=>0
            ]
        ];
        $this->api_post("WooSyncDelete",$body);
        $this->log[]="[NOTIFY DELETE] {$table} ERP:$erp_id";
    }

    public function sync_categories(){
        $body = [
            "tableType"=>"category","id"=>0,"itemId"=>0,"wooItemId"=>0,"syncStatus"=>0,
            "catId"=>0,"wooCatId"=>0,"catName"=>"","storeCode"=>0,"searchText"=>"",
            "rqm"=>[
                "clientCode"=>$this->client,"userName"=>$this->user,
                "password"=>$this->pass,"encrypted"=>0,"compressed"=>0,"sessionId"=>0
            ]
        ];

        $res = $this->api_post("WooSyncGet",$body);
        if(!$res) return;

        $cats = json_decode($res['data'],true);

        foreach($cats as $c){
            $erp_id=$c['id'];
            $cat_id=$c['cat_id'];
            $woo_id=intval($c['woo_cat_id']) ?: 0;
            $name=$c['cat_name'];
            $sync=$c['sync_status'];

            if($sync==1 || $sync===null){
                $term = wp_insert_term($name,'product_cat');
                if(!is_wp_error($term)){
                    $woo_id=$term['term_id'];
                    update_term_meta($woo_id,'_erp_cat_id',$cat_id);
                    $this->log[]="[INFO] Category Added: $name ($woo_id)";
                    $this->notify_update('category',$erp_id,0,0,1,$cat_id,$woo_id);
                }
            }elseif($sync==2){
                if($woo_id){
                    wp_update_term($woo_id,'product_cat',['name'=>$name]);
                    update_term_meta($woo_id,'_erp_cat_id',$cat_id);
                    $this->log[]="[INFO] Category Updated: $name ($woo_id)";
                    $this->notify_update('category',$erp_id,0,0,1,$cat_id,$woo_id);
                }
            }elseif($sync==3){
                if($woo_id) wp_delete_term($woo_id,'product_cat');
                $this->log[]="[INFO] Category Deleted: $name";
                $this->notify_delete('category',$erp_id,0);
            }
        }
    }

    public function sync_products(){
        $body=[
            "tableType"=>"product","id"=>0,"itemId"=>0,"wooItemId"=>0,"syncStatus"=>0,
            "catId"=>0,"wooCatId"=>0,"catName"=>"","storeCode"=>0,"searchText"=>"",
            "rqm"=>[
                "clientCode"=>$this->client,"userName"=>$this->user,
                "password"=>$this->pass,"encrypted"=>0,"compressed"=>0,"sessionId"=>0
            ]
        ];

        $res=$this->api_post("WooSyncGet",$body);
        if(!$res) return;

        $products=json_decode($res['data'],true);

        foreach($products as $p){
            $erp_id     =$p['id'];
            $item_id    =$p['item_id'];
            $woo_id     =intval($p['woo_item_id']) ?: 0;
            $name       =$p['ItemName'] ?: "Unnamed";
            $regular    =$p['LastBuyPrice'] ?? 0;
            $discount   =$p['DiscountValue'] ?? 0;
            $sale       =($discount>0)? max($regular-$discount,0):null;
            $stock      =$p['Raseed'] ?? 0; 
            $instock    =($p['InStock']??false)?"instock":"outofstock";
            $sync       =$p['sync_status'];
            $erp_cat     =$p['cat_id'];
            $barcode    =intval($p['BarCode']) ?: 0;

            $woo_cat_id=$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT term_id FROM {$this->wpdb->termmeta} WHERE meta_key='_erp_cat_id' AND meta_value=%s",$erp_cat
            ));

            if($sync==1 || $sync===null){
                $post_id=wp_insert_post([
                    'post_title'=>$name,'post_status'=>'publish','post_type'=>'product'
                ]);
                if(!$post_id){ $this->log[]="[ERROR] Failed Create: $name"; continue; }
                $woo_id=$post_id;
                update_post_meta($woo_id,'_regular_price',$regular);
                update_post_meta($woo_id,'_price',$sale ?? $regular);
                if($sale!==null) update_post_meta($woo_id,'_sale_price',$sale);
                update_post_meta($woo_id,'_stock',$stock);
                update_post_meta($woo_id,'_stock_status',$instock);
                if($woo_cat_id) wp_set_post_terms($woo_id,[$woo_cat_id],'product_cat');

                if(!empty($p['ImageUrl'])){
                    require_once(ABSPATH.'wp-admin/includes/media.php');
                    $tmp=download_url($p['ImageUrl']);
                    if(!is_wp_error($tmp)){
                        $file=['name'=>basename($p['ImageUrl']),'tmp_name'=>$tmp];
                        $img_id=media_handle_sideload($file,$woo_id);
                        if(!is_wp_error($img_id)) set_post_thumbnail($woo_id,$img_id);
                        else @unlink($tmp);
                    }
                }
                if (!empty($barcode)) {
                    update_post_meta($woo_id, '_sku', sanitize_text_field($barcode));
                }
                $this->log[]="[INFO] Product Added: $name ($woo_id)";
                $this->notify_update('product',$erp_id,$item_id,$woo_id,1,$erp_cat,$woo_cat_id);

            }elseif($sync==2){
                update_post_meta($woo_id,'_regular_price',$regular);
                update_post_meta($woo_id,'_price',$sale ?? $regular);
                if($sale!==null) update_post_meta($woo_id,'_sale_price',$sale);
                else delete_post_meta($woo_id,'_sale_price');
                update_post_meta($woo_id,'_stock',$stock);
                update_post_meta($woo_id,'_stock_status',$instock);
                if($woo_cat_id) wp_set_post_terms($woo_id,[$woo_cat_id],'product_cat');
                $this->log[]="[INFO] Product Updated: $name ($woo_id)";
                $this->notify_update('product',$erp_id,$item_id,$woo_id,1,$erp_cat,$woo_cat_id);

            }elseif($sync==3){
                if($woo_id) wp_delete_post($woo_id,true);
                $this->log[]="[INFO] Product Deleted: $name";
                $this->notify_delete('product',$erp_id,$item_id);
            }
        }
    }

    public function save_log(){
        update_option('erp_debug_log',implode("\n",$this->log));
    }
}

add_action('wp_enqueue_scripts', function () {

    if (!is_checkout()) return;

    wp_enqueue_script(
        'eda-checkout',
        plugin_dir_url(__FILE__) . 'checkout.js',
        ['jquery'],
        '1.0',
        true
    );

    wp_localize_script('eda-checkout', 'EDA_AJAX', [
        'url' => admin_url('admin-ajax.php'),
    ]);
});


add_filter('woocommerce_checkout_fields', function ($fields) {
    $areas = eda_get_governorates();

    // تجهيز options
    $area_options = [
        '' => 'اختر المنطقة'
    ];

    if (is_array($areas)) {
        foreach ($areas as $key => $value) {
            $area_options[$key] = $value;
        }
    }

    $fields['billing']['billing_city'] = [
        'type'     => 'select',
        'label'    => 'المنطقة',
        'required' => true,
        'options'  => $area_options,
        'priority' => 40,
        'class'    => ['form-row-first'],
    ];

    $fields['billing']['billing_district'] = [
        'type'     => 'select',
        'label'    => 'الحي',
        'required' => true,
        'options'  => [
            '' => 'اختر الحي',

        ],
        'priority' => 41,
        'class'    => ['form-row'],
    ];

    return $fields;
});
add_action('woocommerce_checkout_update_order_meta', function ($order_id) {

    if (!empty($_POST['billing_city'])) {
        update_post_meta(
            $order_id,
            '_billing_city',
            sanitize_text_field($_POST['billing_city'])
        );
    }

    if (!empty($_POST['billing_district'])) {
        update_post_meta(
            $order_id,
            '_billing_district',
            sanitize_text_field($_POST['billing_district'])
        );
    }

});
add_action('woocommerce_admin_order_data_after_billing_address', function ($order) {

    $area     = get_post_meta($order->get_id(), '_billing_city', true);
    $district = get_post_meta($order->get_id(), '_billing_district', true);

    if ($area) {
        echo '<p><strong>المنطقة:</strong> ' . esc_html($area) . '</p>';
    }

    if ($district) {
        echo '<p><strong>الحي:</strong> ' . esc_html($district) . '</p>';
    }

});

add_action('woocommerce_thankyou', function ($order_id) {

    $area     = get_post_meta($order_id, '_billing_city', true);
    $district = get_post_meta($order_id, '_billing_district', true);

    if ($area || $district) {
        echo '<h3>تفاصيل العنوان</h3>';
        echo '<p><strong>المنطقة:</strong> ' . esc_html($area) . '</p>';
        echo '<p><strong>الحي:</strong> ' . esc_html($district) . '</p>';
    }

});

// ---------------- AJAX: Get Governorates ----------------
add_action('wp_ajax_eda_get_governorates','eda_get_governorates');
add_action('wp_ajax_nopriv_eda_get_governorates','eda_get_governorates');
function eda_get_governorates(){
    $body = [
        "tableType"=>"Section",
        "id"=>0,"itemId"=>0,"wooItemId"=>0,"syncStatus"=>0,"catId"=>0,"wooCatId"=>0,
        "catName"=>"","storeCode"=>0,"searchText"=>"",
        "rqm"=>["clientCode"=>get_option('erp_client_id'),"userName"=>get_option('erp_username'),"password"=>get_option('erp_password'),"encrypted"=>0,"compressed"=>0,"sessionId"=>0]
    ];
    $resp = wp_remote_post('https://nzstudio.elnozom.me/api/WooCommerce/WooSyncGet',[
        'body'=>json_encode($body),
        'headers'=>['Content-Type'=>'application/json'],
        'timeout'=>30
    ]);
    if(is_wp_error($resp)) wp_send_json_error('Failed');
    // Debug: عرض الرد الخام
    //$data_raw = wp_remote_retrieve_body($resp);
   // file_put_contents(__DIR__.'/debug.txt', $data_raw);

    $data = json_decode(wp_remote_retrieve_body($resp), true);
    $sections = json_decode($data['data'],true);
    $govs = [];
    foreach($sections as $s) $govs[$s['section_id']]=$s['SectionName'];
    return($govs);
}

// ---------------- AJAX: Get Areas ----------------

add_action('wp_ajax_eda_get_areas2','eda_get_areas2');
add_action('wp_ajax_nopriv_eda_get_areas2','eda_get_areas2');
function eda_get_areas2(){
    $gov = intval($_POST['gov']);
    $body = [
        "tableType"=>"area",
        "id"=>0,"itemId"=>0,"wooItemId"=>0,"syncStatus"=>0,"catId"=>0,"wooCatId"=>0,
        "catName"=>"","storeCode"=>0,"searchText"=>"",
        "rqm"=>["clientCode"=>get_option('erp_client_id'),"userName"=>get_option('erp_username'),"password"=>get_option('erp_password'),"encrypted"=>0,"compressed"=>0,"sessionId"=>0]
    ];
    $resp = wp_remote_post('https://nzstudio.elnozom.me/api/WooCommerce/WooSyncGet',[
        'body'=>json_encode($body),
        'headers'=>['Content-Type'=>'application/json'],
        'timeout'=>30
    ]);
    if(is_wp_error($resp)) wp_send_json_error('Failed');

    $data = json_decode(wp_remote_retrieve_body($resp), true);
    $areas = json_decode($data['data'],true);
    $result = [];
    $result2 = [];
    foreach($areas as $a){
        if($a['SectionNo']==$gov){
            $result[$a['area_id']] = $a['AreaName'];
            $result2[$a['area_id']] = $a['DeliveryServiceTotal'];
        }
    }
    wp_send_json_success([
        'result'=>$result,
        'result2'=>$result2
    ]);
}
// ---------------- AJAX: Set Delivery Fee ----------------
add_action('wp_ajax_eda_set_delivery_fee','eda_set_delivery_fee');
add_action('wp_ajax_nopriv_eda_set_delivery_fee','eda_set_delivery_fee');
function eda_set_delivery_fee(){
    if(isset($_POST['fee'])){
        WC()->session->set('eda_delivery_fee', floatval($_POST['fee']));
        wp_send_json_success();
    }
    wp_send_json_error('No fee');
}

// ---------------- Add Delivery Fee to Checkout ----------------
add_action('woocommerce_cart_calculate_fees', function($cart){
    if(is_admin() && !defined('DOING_AJAX')) return;

    $fee = 0;
    if(isset($_POST['eda_delivery_fee'])){
        $fee = floatval($_POST['eda_delivery_fee']);
        WC()->session->set('eda_delivery_fee', $fee);
    } else {
        $fee = WC()->session->get('eda_delivery_fee', 0);
    }
    if($fee > 0){
        $cart->add_fee('سعر التوصيل', $fee, true);
    }
});

add_action('woocommerce_checkout_order_processed', function ($order_id) {

    $order = wc_get_order($order_id);
    if (!$order) return;

    /* ======================
     * العنوان
     * ====================== */
    $city     = get_post_meta($order_id, '_billing_city', true);
    $district = get_post_meta($order_id, '_billing_district', true);

    $address = [
        'first_name' => $order->get_billing_first_name(),
        'last_name'  => $order->get_billing_last_name(),
        'phone'      => $order->get_billing_phone(),
        'email'      => $order->get_billing_email(),
        'address_1'  => $order->get_billing_address_1(),
        'city'       => $city,
        'district'   => $district,
    ];

    /* ======================
     * المنتجات
     * ====================== */
    $items = [];

    foreach ($order->get_items() as $item_id => $item) {

        $product = $item->get_product();

        $items[] = [
            'product_id' => $product->get_id(),
            'sku'        => $product->get_sku(),
            'name'       => $product->get_name(),
            'price'      => wc_get_price_excluding_tax($product),
            'quantity'   => $item->get_quantity(),
            'subtotal'   => $item->get_subtotal(),
            'total'      => $item->get_total(),
        ];
    }

    /* ======================
     * الأسعار
     * ====================== */
    $delivery_fee = 0;
    foreach ($order->get_fees() as $fee) {
        if ($fee->get_name() === 'سعر التوصيل') {
            $delivery_fee = $fee->get_total();
        }
    }

    $prices = [
        'subtotal' => $order->get_subtotal(),
        'delivery' => $delivery_fee,
        'total'    => $order->get_total(),
    ];

    /* ======================
     * كل البيانات مع بعض
     * ====================== */
    $order_data = [
        'order_id' => $order_id,
        'address'  => $address,
        'items'    => $items,
        'prices'   => $prices,
    ];

    // Debug مؤقت
// $upload_dir = __DIR__;

// $path = $upload_dir . '/debug.txt';

// file_put_contents(
//     $path,
//     wp_json_encode($order_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
// );

});
