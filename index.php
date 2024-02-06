<?php
/*
Plugin Name: noon payments
Plugin URI: https://www.noonpayments.com/
Description: Extends WooCommerce with noon payments.
Version: 1.0.2
Supported WooCommerce Versions: 3.7,3.8,3.9,4.2,4.3
Author: noon payments
Author URI: https://www.noonpayments.com/
Copyright: © 2021 noon payments. All rights reserved.
*/

add_action('plugins_loaded', 'woocommerce_noonpay_init', 0);

function woocommerce_noonpay_init()
{

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    /**
     * Localisation
     */
    load_plugin_textdomain('wc-noonpay', false, dirname(plugin_basename(__FILE__)) . '/languages');

    if (isset($_GET['msg']) && $_GET['msg'] != '') {
        add_action('the_content', 'shownoonpayMessage');
    }

    function shownoonpayMessage($content)
    {
        return '<div class="box ' . htmlentities($_GET['type']) . '-box">' . htmlentities(urldecode($_GET['msg'])) . '</div>' . $content;
       
    }
    /**
     * Gateway class
     */
    class WC_Noonpay extends WC_Payment_Gateway
    {
        protected $msg = array();

        protected $logger;

        public function __construct()
        {
            global $wpdb;
            // Go wild in here
            $this->id = 'noonpay';
            $this->method_title = __('noon payments Gateway', 'noonpay');
            $this->method_description = __('Collect payments via cards, Apple Pay', 'noonpay');
            $this->icon = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/noonpaymentslogo.webp';
            $this->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this -> settings['title'];
            $this->description = $this->settings['description'];
            $this->gateway_module = $this->settings['gateway_module'];
            $this->gateway_redirect = $this->settings['gateway_redirect'];
            $this->redirect_page_id = $this->settings['redirect_page_id'];
            $this->styleprofile = $this->settings['styleprofile'];
            $this->business_identifier = $this->settings['business_identifier'];
            $this->gateway_url = $this->settings['gateway_url'];
            $this->application_identifier = $this->settings['application_identifier'];
            $this->authorization_key = $this->settings['authorization_key'];
            $this->credential_key = $this->business_identifier.".".$this->application_identifier.":".$this->authorization_key;
            $this->category = $this->settings['category'];
            $this->language = $this->settings['language'];
            $this->paymentAction = $this->settings['paymentAction'];
            $this->has_fields=true;


            $this->msg['message'] = "";
            $this->msg['class'] = "";

            add_action('init', array(&$this, 'check_noonpay_response'));

            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_noonpay_response'));

            add_action('valid-noonpay-request', array(&$this, 'SUCCESS'));

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }

            add_action('woocommerce_receipt_noonpay', array(&$this, 'receipt_page'));
            //add_action('woocommerce_thankyou_noonpay', array(&$this, 'thankyou_page'));
            
            if ($this->settings['enabled'] == 'yes') { //Update session cookies
                $this->manage_session();
            }
            
            $this->logger = wc_get_logger();
        }

        public function init_form_fields()
        {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'noonpay'),
                    'type' => 'checkbox',
                    'label' => __('Enable noon payments', 'noonpay'),
                    'default' => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'Title to appear at checkout',
                    'default'     => 'Pay by cards, Apple Pay',
                ),
                'description' => array(
                    'title' => __('Description:', 'noonpay'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'noonpay'),
                    'default' => __('Pay securely through noon paymemts.', 'noonpay')
                ),
                'gateway_module' => array(
                    'title' => __('Gateway Mode', 'noonpay'),
                    'type' => 'select',
                    'options' => array("0" => "Select", "test" => "Test", "live" => "Live"),
                    'description' => __('Mode of gateway subscription.', 'noonpay')
                ),
                'gateway_url' => array(
                    'title' => __('Gateway Url', 'noonpay'),
                    'type' => 'text',
                    'description' =>  __('Gateway Url to connect to', 'noonpay')
                ),
                'gateway_redirect' => array(
                    'title' => __('Operating Mode', 'noonpay'),
                    'type' => 'select',
                    'options' => array("redirect" => "Redirect", "popup" => "Lightbox"),
                    'description' => __('Redirect the customer or popup a dialog.', 'noonpay')
                ),
                'business_identifier' => array(
                    'title' => __('Business Identifier', 'noonpay'),
                    'type' => 'text',
                    'description' =>  __('Business Identifier (case sensitive)', 'noonpay')
                ),
                'application_identifier' => array(
                    'title' => __('Application Identifier', 'noonpay'),
                    'type' => 'text',
                    'description' =>  __('Application Identifier (case sensitive)', 'noonpay')
                ),
                'authorization_key' => array(
                    'title' => __('Authorization Key', 'noonpay'),
                    'type' => 'text',
                    'description' =>  __('Key (case sensitive)', 'noonpay')
                ),
                'paymentAction' => array(
                    'title' => __('Payment Action', 'noonpay'),
                    'type' => 'select',
                    'options' => array("SALE" => "Sale", "AUTHORIZE" => "Authorize"),
                    'description' =>  __('Payment action - request Authorize or Sale ', 'noonpay')
                ),
                'category' => array(
                    'title' => __('Order route category', 'noonpay'),
                    'type' => 'text',
                    'description' =>  __('Order route category. E.g. pay', 'noonpay')
                ),
                'language' => array(
                    'title' => __('Payment language', 'noonpay'),
                    'type' => 'select',
                    'options' => array("0" => "Select", "en" => "English", "ar" => "Arabic"),
                    'description' =>  __("Language to display the checkout page in.", 'noonpay')
                ),
                'styleprofile' => array(
                    'title' => __('Style Profile', 'noonpay'),
                    'type' => 'text',
                    'description' =>  __("Style Profile name configured in Merchant Panel (optional)", 'noonpay')
                ),
                'redirect_page_id' => array(
                    'title' => __('Return Page'),
                    'type' => 'select',
                    'options' => $this->get_pages('Select Page'),
                    'description' => "Page to redirect to after processing payment."
                )
            );
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         **/
        public function admin_options()
        {
            echo '<h3>' . __('noon payments', 'noonpay') . '</h3>';
            echo '<p>' . __('A popular gateways for online shopping.') . '</p>';
            if (PHP_VERSION_ID < 70300) {
                echo "<h1 style=\"color:red;\">**Notice: noon payments plugin requires PHP v7.3 or higher.<br />
	  		 		Plugin will not work properly below PHP v7.3 due to SameSite cookie restriction.</h1>";
            }
            echo '<table class="form-table">';
            $this->generate_settings_html();
            echo '</table>';
        }

        /**
         *  There are no payment fields, but we want to show the description if set.
         **/
        public function payment_fields()
        {
            if ($this->description) {
                echo wpautop(wptexturize($this->description));
            }
        }

        /**
         * Receipt Page
         **/
        public function receipt_page($order)
        {
            echo '<p>' . __('Thank you for your order, please wait as you will be automatically redirected to noon payments.', 'noonpay') . '</p>';
            echo $this->generate_noonpay_form($order);
        }

        /**
         * Process the payment and return the result
         **/
        public function process_payment($order_id)
        {
            $order = new WC_Order($order_id);

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg(
                        'order',
                        $order->id,
                        add_query_arg('key', $order->get_order_key(), $order->get_checkout_payment_url(true))
                    )
                );
            } else {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg(
                        'order',
                        $order->id,
                        add_query_arg('key', $order->get_order_key(), get_permalink(get_option('woocommerce_pay_page_id')))
                    )
                );
            }
        }

        /**
         * Check for valid noon pay server callback
         **/
        public function check_noonpay_response()
        {

            global $woocommerce;
            
            $redirect_url ='';
            
            if (!isset($_GET['wc-api'])) {
                //invalid response
                $this->msg['class'] = 'error';
                $this->msg['message'] = "Invalid payment gateway response...";

                wc_add_notice($this->msg['message'], $this->msg['class']);

                $redirect_url = add_query_arg(array('msg' => urlencode($this->msg['message']), 'type' => $this->msg['class']), $redirect_url);

                wp_redirect($redirect_url);
                exit;
            }
            
            if ($_GET['wc-api'] == get_class($this)) {
                $responsedata = $_GET;
                //the orderId in the response is the noon payments order reference number
                if (isset($responsedata['orderId']) && !empty($responsedata['orderId'])) {

                    $txnid = WC()->session->get('noopay_order_id');
                    ;
                    $order_id = explode('_', $txnid);
                    $order_id = (int) $order_id[0];    //get rid of time part
                    
                    $order = new WC_Order($order_id);
                    $noonReference = $responsedata['orderId'];

                    $action = $this->paymentAction;

                    $this->msg['class'] = 'error';
                    $this->msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";
                    
                    if ($this->verify_payment($order, $noonReference, $txnid)) {
                        $this->msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful for Order Id: $order_id <br/>
						We will be shipping your order to you soon.<br/><br/>";
                        if($this->paymentAction == 'AUTHORIZE') {
                            $this->msg['message'] = "Thank you for shopping with us. Your payment has been authorized for Order Id: $order_id <br/>
						We will be shipping your order to you soon.<br/><br/>";
                        }

                        $this->msg['class'] = 'success';

                        if ($order->status == 'processing' || $order->status == 'completed') {
                            //do nothing
                        } else {
                            //complete the order
                            $order->payment_complete();
                            $order->add_order_note('noon payments has processed the payment - '. $action . ' Ref Number: ' . $responsedata['orderReference']);
                            $order->add_order_note($this->msg['message']);
                            $order->add_order_note("Paid using noon payments");
                            $woocommerce->cart->empty_cart();
                            $redirect_url = $order->get_checkout_order_received_url();
                        }
                    } else {
                        //failed
                        $this->msg['class'] = 'error';
                        $this->msg['message'] = "Thank you for shopping with us. However, the payment failed<br/><br/>";
                        $order->update_status('failed');
                        $order->add_order_note('Failed');
                        $order->add_order_note($this->msg['message']);
                    }
                    
                }
            }

            //manage msessages
            if (function_exists('wc_add_notice')) {
                wc_clear_notices();
                if($this->msg['class']!='success') {
                    wc_add_notice($this->msg['message'], $this->msg['class']);
                }
            } else {
                if ($this->msg['class'] != 'success') {
                    $woocommerce->add_error($this->msg['message']);
                }
                $woocommerce->set_messages();
            }

            if($redirect_url == '') {
                $redirect_url = ($this->redirect_page_id == "" || $this->redirect_page_id == 0) ? get_site_url() . "/" : get_permalink($this->redirect_page_id);
            }
            //For wooCoomerce 2.0
            //$redirect_url = add_query_arg( array('msg'=> urlencode($this -> msg['message']), 'type'=>$this -> msg['class']), $redirect_url );
            wp_redirect($redirect_url);
            exit;
        }

        // Verify the payment
        private function verify_payment($order, $noonReference, $txnid)
        {
            global $woocommerce;

            try {
                
                $url = $this->gateway_url.'/'.$noonReference;

                $headerField = 'Key_Live';
                $headerValue = base64_encode($this->credential_key);
    
                if ($this->gateway_module == 'test') {
                    $headerField = 'Key_Test';
                }
    
                $header = array();
                $header[] = 'Content-type: application/json';
                $header[] = 'Authorization: '.$headerField.' '.$headerValue;
            
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($curl, CURLOPT_SSLVERSION, 6);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($curl, CURLOPT_ENCODING, '');
                curl_setopt($curl, CURLOPT_TIMEOUT, 60);
                
                $response = curl_exec($curl);
                $curlerr = curl_error($curl);

                if ($curlerr != '') {
                    return false;
                } else {
                    $res = json_decode($response);

                    if (isset($res->resultCode) && $res->resultCode == 0) {
                        if (isset($res->result->transactions[0]->status) && $res->result->transactions[0]->status == 'SUCCESS') {
                            if (isset($res->result->order->totalCapturedAmount) && isset($res->result->order->totalSalesAmount)
                                    && isset($res->result->order->totalRemainingAmount) && isset($res->result->order->reference)) {
                                $capturedAmount = $res->result->order->totalCapturedAmount;
                                $saleAmount = $res->result->order->totalSalesAmount;
                                $txn_id_ret = $res->result->order->reference;
                                $remainingAmount = $res->result->order->totalRemainingAmount;
                                $orderAmount = $order->order_total;

                                if ($this->paymentAction == "SALE" && $orderAmount == $saleAmount && $capturedAmount >= $orderAmount	&& $txn_id_ret == $txnid) {
                                    return true;
                                } elseif ($this->paymentAction == "AUTHORIZE" && $orderAmount == $remainingAmount	&& $txn_id_ret == $txnid) {
                                    return true;
                                } else {
                                    return false;
                                }
                            }
                        }
                    }
                }
                return false;
            } catch (Exception $e) {
                return false;
            }
        }

        /**
         * Generate noo payment button link
         **/
        public function generate_noonpay_form($order_id)
        {

            global $woocommerce;

            $order = new WC_Order($order_id);

            $order_currency = $order->get_currency();

            $redirect_url =  get_site_url() . "/";

            //For wooCoomerce 2.0
            $redirect_url = add_query_arg('wc-api', get_class($this), $redirect_url);
            $order_id = $order_id . '_' . date("ymd") . ':' . rand(1, 100);

            $productInfo = "";
            $order_items = $order->get_items();
            foreach($order_items as $item_id => $item_data) {
                $product = wc_get_product($item_data['product_id']);
                if ($product->get_sku() != "") {
                    $productInfo .= $product->get_sku()." " ;
                }
            }
            if ($productInfo != "") {
                $productInfo = trim($productInfo);
                if(strlen($productInfo) > 50) {
                    $productInfo = substr($productInfo, 0, 50);
                }
            } else {
                $productInfo = "Product Info";
            }
            
            $postValues =  array();
            $orderValues = array();
            $confiValue = array();

            $postValues['apiOperation'] = 'INITIATE';
            $orderValues['name'] = $productInfo;
            $orderValues['channel'] = 'web';
            $orderValues['reference'] = $order_id;
            $orderValues['amount'] = $order->order_total;
            $orderValues['currency'] = $order->get_currency();
            $orderValues['category'] = $this->category;
            
            $confiValue['locale'] = $this->language;
            $confiValue['paymentAction'] = $this->paymentAction;
            $confiValue['returnUrl'] = $redirect_url;
            if(!empty($this->styleprofile)) {
                $confiValue['styleProfile'] = $this->styleprofile;
            }
            
            $postValues['order'] = $orderValues;
            $postValues['configuration'] = $confiValue;
        
            $postJson = json_encode($postValues);
        
            $action = '';
            $jsscript = '';
            $url = $this->gateway_url;
            $headerField = 'Key_Live';
            $headerValue = base64_encode($this->credential_key);

            if ($this->gateway_module == 'test') {
                $headerField = 'Key_Test';
            }

            $header = array();
            $header[] = 'Content-type: application/json';
            $header[] = 'Authorization: '.$headerField.' '.$headerValue;

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_SSLVERSION, 6);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curl, CURLOPT_ENCODING, '');
            curl_setopt($curl, CURLOPT_TIMEOUT, 60);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $postJson);
            $response = curl_exec($curl);
            $curlerr = curl_error($curl);
            $paymentJS = "https://cdn-stg.noonpayments.com/script/v1/noonpayments.js";

            if ($curlerr != '') {
                wc_print_notice($curlerr, "error");
                return false;
            } else {
                $res = json_decode($response);
                if (isset($res->resultCode) && $res->resultCode == 0 &&
                        isset($res->result->checkoutData->postUrl) && isset($res->result->order->id)) {
                    $action = $res->result->checkoutData->postUrl;
                    $jsscript = $res->result->checkoutData->jsUrl;
                    $orderReference = $res->result->order->id;
                    if (empty($action) || empty($jsscript) || empty($orderReference)) {
                        wc_print_notice('Payment Action could not be initiated. Verify credentials/checkout info.', "error");
                        return false;
                    } else {
                        //add txnid and orderReference to session to validate order
                        WC()->session->set('noopay_order_id', $order_id);
                    }
                } else {
                    wc_print_notice('Gateway did not return any response. Contact Administrator.', "error");
                    return false;
                }
                
            }


            ob_start(); ?>
<html>
<style>
    .wrapper {
        margin-bottom: 200px;
    }

    .none {
        display: none;
    }

    .block {
        display: block;
    }

    .payment-container {
        width: 500px;
        margin: 0 auto;
    }

    .payment-container input[type=checkbox],
    input[type=radio] {
        box-sizing: border-box;
        padding: 0;
    }

    @media only screen and (max-width: 768px) {
        .payment-container {
            width: 100%;
        }
    }

    .new-card-box {
        padding: 10px 16px;
        background: #fff;
        border: 1px solid #000;
        box-sizing: border-box;
        border-radius: 4px;
    }

    .flex {
        display: -ms-flexbox;
        display: flex;
    }

    .flex-50 {
        flex: 50;
        -ms-flex: 50;
    }

    .flex-35 {
        flex: 35;
        -ms-flex: 35;
    }

    .align-center {
        -ms-flex-align: center;
        align-items: center;
    }

    .space-between {
        -ms-flex-pack: justify;
        justify-content: space-between;
        margin-bottom: .5rem;
    }

    .horizontal-line {
        margin-top: 0 !important;
        border: 0;
        margin-left: 0;
        border-top: 1px solid rgba(0, 0, 0, .1);
        margin-bottom: 1rem;
    }

    label {
        display: inline-block;
        margin-bottom: .5rem;
    }

    .card-number-input-box {
        display: -ms-flexbox;
        display: flex;
        -ms-flex-pack: start;
        justify-content: flex-start;
        -ms-flex-align: baseline;
        align-items: baseline;
    }

    .box {
        cursor: text;
        font-size: 14px;
        width: 100%;
        padding: 10px;
        background: #fff;
        box-sizing: border-box;
        border-radius: 4px;
        height: 44px;
        border: 1px solid #ccc;
        padding: 0;
        display: flex;
        align-items: center;
        padding-left: 10px;
    }

    .box-focus {
        border: 2px solid #3866DF !important;
        /*box-shadow: 0 0 5px #3866DF;*/
    }

    .box-error {
        border: 2px solid #ca5e58 !important;
        box-shadow: 0 0 5px #ca5e58 !important;
    }

    .error-msg {
        color: red;
        margin-top: 8px;
        margin-left: 2px;
    }


    .frame-container {
        height: 30px;
    }

    .card-input-icon {
        margin-left: auto;
    }

    .img-60-20 {
        width: 60px;
        height: 20px;
        margin: 0 0 0 -3px;
    }

    .cvv-expiry-container-box {
        justify-content: space-evenly;
        display: flex;
    }

    .expiry-container-box {
        margin-right: 10px;
        width: 42%;
        flex: 40;
    }

    .cvv-container-box {
        flex: 30;
        -ms-flex: 30;
        width: 42%;
        margin-right: 10px;
    }

    #pay-btn {
        width: 100%;
    }


    /*Saved cards section*/

    .np-saved-card-brand-image {
        height: 20px;
        width: 60px;
    }

    .np-saved-card-cvv-container {
        border-radius: 4px;
        border: 1px solid #000;
    }

    .new-card-control-box {
        width: 100%;
    }

    .np-saved-card-info-container-inner,
    .new-card-control-box div,
    .np-save-card-box {
        display: flex;
        width: 35%;
        align-items: end;
    }

    .np-saved-card-info-container-inner input,
    .new-card-control-box div input,
    .np-save-card-box input {
        flex: 5;
    }

    .np-saved-card-info-container-inner label,
    .new-card-control-box div label,
    .np-save-card-box label {
        flex: 30;
        text-align: left;
        margin: 0;
        font-weight: 700 !important;
    }

    .btn {
        background-color: #977aba;
        border-color: #977aba;
        color: #FFFFFF;

        display: inline-block;
        padding: 6px 12px;
        margin-bottom: 0;
        font-size: 14px;
        font-weight: 400;
        line-height: 1.42857143;
        text-align: center;
        white-space: nowrap;
        vertical-align: middle;
        -ms-touch-action: manipulation;
        touch-action: manipulation;
        cursor: pointer;
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
        user-select: none;
        background-image: none;
        border: 1px solid transparent;
        border-radius: 4px;
    }

    .btn:hover {
        background-color: #583f79;
    }

    apple-pay-button {
        --apple-pay-button-width: 150px;
        --apple-pay-button-height: 30px;
        --apple-pay-button-border-radius: 3px;
        --apple-pay-button-padding: 0px 0px;
        --apple-pay-button-box-sizing: border-box;
    }
</style>

<body>
    <script src="https://cdn-stg.noonpayments.com/script/v1/noonpayments.js"></script>
    <!-- <script async crossorigin src="https://applepay.cdn-apple.com/jsapi/v1.1.0/apple-pay-sdk.js"></script> -->


    <script type="text/javascript">
        // document.getElementById("paynoon_form").submit();
        document.addEventListener("DOMContentLoaded", function() {
            var defaultErrorCode = "GENERAL_ERROR";
            // can we have list of val error codes here.
            var valErrors = {
                "GENERAL_ERROR": "Something went wrong",
                "NO_TRANSACTION_CONFIG_PROVIDED": "Merchant error - transaction configuration is invalid",
                "INVALID_ORDER_REFERENCE_URL_TYPE": "Merchant error - invalid order url reference",
                "NO_ORDER_REFERENCE_URL_PROVIDED": "Merchant error - no order url reference provided",
                "NO_CONTAINERS_PROVIDED": "Merchant error - no containers provided",
                "NO_CARD_NUMBER_CONTAINER_PROVIDED": "Merchant error - card number container ",
                "NO_CARD_HOLDER_NAME_CONTAINER_PROVIDED": "Merchant error - card number container is not provided",
                "NO_CVV_CONTAINER_PROVIDED": "Merchant error - cvv container is not provided",
                "NO_EXPIRY_DATE_CONTAINER_PROVIDED": "Merchant error - expiry date container is not provided",
                "NO_PAY_BUTTON_PROVIDED": "Merchant error - pay now button is not provided",
                "NO_SAVED_CARDS_CONTAINER_PROVIDED": "Merchant error - saved cards container is not provided",
                "NO_SAVE_CARD_CONTAINER_PROVIDED": "Merchant error - save card container is not provided",
                "NO_CARD_PAYMENT_SELECTION_CONTAINER_PROVIDED": "Merchant error - payment selection container is not provided",
                "INVALID_ORDER_REFERENCE_URL": "Merchant error - invalid order url reference",
                "ORDER_ID_IS_MISSING": "Merchant error - order id is missing",
                "INVALID_ORDER_ID": "Merchant error - order id is invalid",
                "INVALID_LOCALE": "Merchant error - locale is invalid",
                "NO_PAYMENT_OPTION_SELECTED": "Select payment method",
                "NOT_SUPPORTED_CARD_BRAND": "Sorry, the entered card is not supported for this order",
                "ORDER_HAS_BEEN_EXPIRED": "Order has been expired",
                "ORDER_ALREADY_PROCESSED": "Order already processed",
                "NO_PAYMENT_OPTION_AVAILABLE": "There is no payment option available for this order",
                "CARD_PAYMENT_OPTION_NOT_AVAILABLE": "Card payment option is not available",
                "CARD_NUMBER_IS_NOT_VALID": "Please enter a valid card number",
                "CARD_NUMBER_IS_MISSING": "Please enter the card number",
                "UNSUPPORTED_CARD": "This card is not supported",
                "MONTH_NOT_VALID": "Please enter a valid month",
                "DATE_NOT_VALID": "Please enter a valid date",
                "YEAR_NOT_VALID": "Please enter a valid year",
                "MISSING_CARD_HOLDER_NAME_ERROR": "Please enter the cardholder's name",
                "INVALID_CARD_HOLDER_NAME_MAX_LENGTH": "Maximum 100 characters allowed",
                "MISSING_CVV_ERROR": "Please enter the CVV",
                "INVALID_CVV_ERROR": "Please enter a valid CVV"
            };

            var paymentBox = document.getElementById("payment-box");

            var savedCardBox = document.getElementById("saved-cards-box");
            var savedCardFrame = document.getElementById("saved-cards-container");

            var newCardBox = document.getElementById("new-card-box");
            var newCardSelectionFrame = document.getElementById("new-card-payment-selection-frame");
            var newCardSelectionFrameContainer = document.getElementById("new-card-selection-container");

            var cardNumberFrame = document.getElementById("card-number-input-box-frame");

            var cardHolderFrame = document.getElementById("card-holder-input-box-frame");
            var cardHolderFrameContainer = document.getElementById("card-holder-name-container");

            var cardExpiryFrame = document.getElementById("expiry-input-box-frame");

            var cardCvvFrame = document.getElementById("cvv-input-box-frame");
            var cardCvvFrameContainer = document.getElementById("cvv-container-box");

            var saveCardFrame = document.getElementById("save-card-container");

            var payBtn = document.getElementById("pay-btn");
            var payBtnAmnt = document.getElementById("pay-btn-amnt");

            var cardNumberInputBox = document.getElementById("card-number-input-box");
            var cardHolderInputBox = document.getElementById("card-holder-input-box");
            var cardExpiryInputBox = document.getElementById("expiry-input-box");
            var cardCvvInputBox = document.getElementById("cvv-input-box");
            var cardBrandIcon = document.getElementById("card-brand-icon");


            var generalErrorMsg = document.getElementById("general-error-msg");
            var cvvErrorMsg = document.getElementById("cvv-error-msg");
            var expiryErrorMsg = document.getElementById("expiry-error-msg");
            var cardHolderErrorMsg = document.getElementById("card-holder-error-msg");
            var cardNumberErrorMsg = document.getElementById("card-number-error-msg");

            var config = {
                orderUrlReference: '<?php echo $action; ?>',
                containers: {
                    cardNumber: {
                        container: new noonPayments.defaultContainerAdaptor(cardNumberFrame)
                    },
                    cardHolderName: {
                        container: new noonPayments.defaultContainerAdaptor(cardHolderFrame)
                    },
                    expiryDate: {
                        container: new noonPayments.defaultContainerAdaptor(cardExpiryFrame)
                    },
                    cvv: {
                        container: new noonPayments.defaultContainerAdaptor(cardCvvFrame)
                    },
                    savedCards: {
                        container: new noonPayments.defaultContainerAdaptor(savedCardFrame)
                    },
                    saveCard: {
                        container: new noonPayments.defaultContainerAdaptor(saveCardFrame)
                    },
                    newCardPaymentSelection: {
                        container: new noonPayments.defaultContainerAdaptor(newCardSelectionFrame)
                    },
                    payButton: {
                        button: new noonPayments.defaultButtonAdaptor(payBtn)
                    }
                },
                selectedEmiPlan: getSelectedEmiPlan
            };


            var transaction = new noonPayments.newTransaction(config);

            transaction.on(noonPayments.events.transactionCompleted, handleTransactionComplete);

            transaction.on(noonPayments.events.validationError, handleValidationError);

            transaction.on(noonPayments.events.inputFocusChanged, handleInputFocusChanged);

            transaction.on(noonPayments.events.inputValidationChanged, handleInputValidationChanged);

            transaction.on(noonPayments.events.cardBrandChanged, handleCardBrandChanged);

            transaction.on(noonPayments.events.transactionProcessingStarted,
                handleTransactionProcessingStarted);

            transaction.on(noonPayments.events.paymentSelectionChanged, handlePaymentSelectionChanged);

            transaction.on(noonPayments.events.formValidationStatusChanged, handleValidationStatusChanged);

            transaction.on(noonPayments.events.emiDataChanged, function(data) {
                renderEmiBank(data);
            });

            transaction.prepare().then(configureUi, handlePrepareError);


            function getSelectedEmiPlan() {
                var input = document.querySelector('input[name="plan"]:checked');
                return input ? input.value : null;
            }

            function handleTransactionProcessingStarted() {
                payBtn.setAttribute("disabled", "disabled");
            }

            function handlePaymentSelectionChanged(data) {

                while (paymentBox.getElementsByClassName("box-error").length > 0) {
                    var errorBox = paymentBox.getElementsByClassName("box-error")[0];
                    addOrRemoveClass(errorBox, "box-error", false);
                }

                var errorMsgs = paymentBox.getElementsByClassName("error-msg");
                for (var j = 0; j < errorMsgs.length; j++) {
                    var errorMsg = errorMsgs[j];
                    errorMsg.style.display = "none";
                }
            }

            function handleValidationStatusChanged(isValid) {
                var disabled = 'disabled';

                if (isValid) {
                    payBtn.removeAttribute(disabled);
                } else {
                    payBtn.setAttribute(disabled, disabled);
                }
            }

            function handleInputFocusChanged(event) {
                var element = getElementByIdentifier(event.identifier);
                addOrRemoveClass(element.boxElem, "box-focus", event.isFocused);
            }

            function handleCardBrandChanged(event) {
                var src = event ? event.logo : transaction.defaultCardBrandLogo;
                cardBrandIcon.src = src;
            }

            function handleInputValidationChanged(event) {

                console.log(event);
                var element = getElementByIdentifier(event.identifier);
                addOrRemoveClass(element.boxElem, "box-error", !event.isValid);

                var error = !event.isValid ? valErrors[event.validationErrorCode] || valErrors[
                    defaultErrorCode] : "";
                toggleError(element.errorMsgElement, error);

            }

            function handleValidationError(validationError) {
                var msg = validationError && validationError.errorCode ? valErrors[validationError
                        .errorCode] :
                    valErrors[defaultErrorCode];
                msg = msg ? msg : valErrors[defaultErrorCode];
                toggleError(generalErrorMsg, msg);

                payBtn.removeAttribute("disabled");
            }

            function handleTransactionComplete(transaction) {
                if (transaction.isRedirectionRequired) {
                    getBody().appendChild(transaction.redirectionForm);
                    transaction.redirectionForm.submit();
                } else {
                    window.location = "/status?status=succes"
                }
            }

            function handlePrepareError(error) {
                var msg = error && error.errorCode ? valErrors[error.errorCode] : valErrors[
                    defaultErrorCode];
                msg = msg ? msg : valErrors[defaultErrorCode];
                var prepareErrorMsgElem = document.getElementById("prepare-error");
                toggleError(prepareErrorMsgElem, msg);
            }

            function configureUi(orderConfig) {

                if (orderConfig.emiBanks.length > 0) {
                    renderEmiBank([]);
                }

                if (orderConfig.newCard.isVisible) {
                    cardBrandIcon.src = transaction.defaultCardBrandLogo;

                    if (orderConfig.newCard.isCardHolderNameVisible) {
                        showElement(cardHolderFrameContainer, true);
                    }

                    if (orderConfig.newCard.isCvvVisible) {
                        showElement(cardCvvFrameContainer, true);
                    }

                    if (orderConfig.newCard.isNewCardPaymentSelectionVisible) {
                        showElement(newCardSelectionFrameContainer, true);
                    }

                    showElement(newCardBox, true);
                }

                if (orderConfig.savedCards.isVisible) {
                    showElement(savedCardBox, true);
                }

                payBtnAmnt.innerText = orderConfig.orderDetail.currency + " " + orderConfig.orderDetail
                    .totalOrderAmountFormatted;

                showElement(paymentBox, true);
            }

            function showElement(domElement, isVisible) {
                if (domElement) {
                    domElement.style.display = isVisible ? "block" : "none";
                }
            }

            // function renderEmiBank(emiBanks) {
            //     if (emiBanks && emiBanks.length && emiBanks.length > 0) {
            //         var template = document.getElementById('template').innerHTML;
            //         var rendered = Mustache.render(template, {
            //             emiBanks: emiBanks
            //         });
            //         document.getElementById('emiHtmlContainer').innerHTML = rendered;
            //     } else {
            //         var noDataTemplate = document.getElementById('no-data-template').innerHTML;
            //         var noDataTemplateRender = Mustache.render(noDataTemplate, {
            //             emiBanks: emiBanks
            //         });
            //         document.getElementById('emiHtmlContainer').innerHTML = noDataTemplateRender;
            //     }
            // }

            function getBody() {
                return document.getElementsByTagName("body")[0];
            }

            function getElementByIdentifier(identifier) {

                if (identifier === noonPayments.componentIdentifiers.cardNumber) return {
                    boxElem: cardNumberInputBox,
                    errorMsgElement: cardNumberErrorMsg
                };
                if (identifier === noonPayments.componentIdentifiers.expiryDate) return {
                    boxElem: cardExpiryInputBox,
                    errorMsgElement: expiryErrorMsg
                };
                if (identifier === noonPayments.componentIdentifiers.cvv) return {
                    boxElem: cardCvvInputBox,
                    errorMsgElement: cvvErrorMsg
                };
                if (identifier === noonPayments.componentIdentifiers.cardHolderName) return {
                    boxElem: cardHolderInputBox,
                    errorMsgElement: cardHolderErrorMsg
                };


                var selector = '[np-identifier="' + identifier + '"]';
                var savedCard = document.querySelector(selector);
                if (savedCard) {
                    var elem = savedCard.getElementsByClassName("np-saved-card-cvv-container")[0];
                    return {
                        boxElem: elem,
                        errorMsgElement: generalErrorMsg
                    };
                }


                return {
                    errorMsgElement: generalErrorMsg
                };
            }

            function addOrRemoveClass(element, className, shouldAdd) {
                if (element) {
                    if (shouldAdd) {
                        element.classList.add(className);
                    } else {
                        element.classList.remove(className);
                    }
                }
            }

            function toggleError(element, error) {

                if (element) {
                    element.innerText = error;

                    if (error)
                        element.style.display = "block";
                    else
                        element.style.display = "none";
                }
            }

        });
    </script>


    <div class="wrapper wrapper-content animated fadeInRight">
        <div class="payment-container" id="payment-box" style="display: none; margin-top: 15px;">

            <!-- saved cards (will list saved cards) -->
            <div id="saved-cards-box" class="saved-cards-box" style="display: none">
                <div id="saved-cards-container"></div>
            </div>

            <div id="new-card-box" class="new-card-box" style="display: none">

                <!-- new card selection container (Will show check box to select new card.) -->

                <div class="new-card-selection-container" id="new-card-selection-container">
                    <div class="flex align-center space-between">
                        <div class="new-card-control-box" id="new-card-payment-selection-frame">
                        </div>
                    </div>
                </div>

                <!-- New card fields container-->
                <div style="padding-top: 2%;">
                    <hr class="horizontal-line">

                    <div class="card-container">
                        <div class="space-between flex">
                            <div class="card-number-box flex-50">
                                <label>Card Number</label>
                                <div class="card-number-input-box box box-out-focus" id="card-number-input-box">
                                    <div id="card-number-input-box-frame" class="frame-container"></div>
                                    <div class="card-input-icon">
                                        <img alt="Default credit card logo" id="card-brand-icon" src=""
                                            class="img-60-20">
                                    </div>
                                </div>
                                <div class="error-msg" id="card-number-error-msg" style="display: none;"></div>

                            </div>
                        </div>

                        <div class="card-holder-name-container space-between flex" id="card-holder-name-container"
                            style="display: none">
                            <div class="card-holder-box flex-50">
                                <label>Card Holder Name</label>
                                <div class="box box-out-focus" id="card-holder-input-box">
                                    <div id="card-holder-input-box-frame" class="frame-container"></div>
                                </div>
                                <div class="error-msg" id="card-holder-error-msg" style="display: none;"></div>

                            </div>
                        </div>

                        <div class="space-between flex">

                            <div class="cvv-expiry-container-box flex-35">
                                <div class="expiry-container-box">
                                    <label>Card Expiry</label>
                                    <div class="expiry-input-box box box-out-focus" id="expiry-input-box">
                                        <div id="expiry-input-box-frame" class="frame-container"></div>
                                    </div>
                                    <div class="error-msg" id="expiry-error-msg" style="display: none;"></div>

                                </div>

                                <div class="cvv-container-box" id="cvv-container-box" style="display: none">
                                    <label>CVV</label>
                                    <div class="cvv-input-box box box-out-focus" id="cvv-input-box">
                                        <div id="cvv-input-box-frame" class="frame-container"></div>
                                    </div>
                                    <div class="error-msg" id="cvv-error-msg" style="display: none;"></div>

                                </div>
                            </div>

                        </div>
                    </div>
                    <div class="save-card">
                        <div id="save-card-container"></div>
                    </div>

                    <div class="error-msg" id="general-error-msg" style="display: none;"></div>
                </div>


            </div>



            <div class="text-center" style="margin-top: 15px;">
                <div class="btn btn-primary" id="pay-btn" disabled="disabled">Pay <span id="pay-btn-amnt"></span></div>
                <apple-pay-button buttonstyle="black" type="plain" locale="en-US"></apple-pay-button>
            </div>
            <div>

            </div>
        </div>

        <h3 style="color: red; display: none" id="prepare-error"></h3>
        <!-- <form action="<?php echo $action; ?>" method="post"
        id="paynoon_form" name="paynoon_form">
        <button id='submit_noonpay_payment_form' name='submit_noonpay_payment_form'>Apple Pay</button>

        </form> -->
    </div>

</html>
<?php
            return ob_get_clean();
        

        }
        public function get_pages($title = false, $indent = true)
        {
            $wp_pages = get_pages('sort_column=menu_order');
            $page_list = array();
            if ($title) {
                $page_list[] = $title;
            }
            foreach ($wp_pages as $page) {
                $prefix = '';
                // show indented child pages?
                if ($indent) {
                    $has_parent = $page->post_parent;
                    while ($has_parent) {
                        $prefix .=  ' - ';
                        $next_page = get_page($has_parent);
                        $has_parent = $next_page->post_parent;
                    }
                }
                // add to page list array array
                $page_list[$page->ID] = $prefix . $page->post_title;
            }
            return $page_list;
        }
        
        /**
        * Session patch CSRF Samesite=None; Secure
        **/
        public function manage_session()
        {
            $context = array('source' => $this->id);
            try {
                if (PHP_VERSION_ID >= 70300) {
                    $options = session_get_cookie_params();
                    $options['samesite'] = 'None';
                    $options['secure'] = true;
                    unset($options['lifetime']);
                    $cookies = $_COOKIE;
                    foreach ($cookies as $key => $value) {
                        if (!preg_match('/cart/', $key)) {
                            setcookie($key, $value, $options);
                        }
                    }
                } else {
                    $this->logger->error("noon payment plugin does not support this PHP version for cookie management.
												Required PHP v7.3 or higher.", $context);
                }
            } catch (Exception $e) {
                $this->logger->error($e->getMessage(), $context);
            }
        }
    }

        

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_noonpay_gateway($methods)
    {
        $methods[] = 'WC_Noonpay';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_noonpay_gateway');
}
?>