<?php
/*  Copyright 2018  Prathibha Sathyajth (email : prathibha_w@epiclanka.net)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 3, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 * Plugin Name: WooCommerce Epic payment gateway
 * Plugin URI: http://www.epictechnology.lk
 * Description: Epic payment gateway for WooCommerce
 * Version: 1.0.0
 * Author: prathiha_w
 * Author Email: prathiha_w@epiclanka.net
 * License: GPL3
 *
 * Text Domain: wc_epic_payment_gateway
 *
 *
 */

add_action('plugins_loaded', 'init_wc_myepic_payment_gateway', 0);

add_action('admin_notices', 'wc_myepic_payment_gateway_admin_notice_mcrypt');
function wc_myepic_payment_gateway_admin_notice_mcrypt()
{

    if (!function_exists('mcrypt_encrypt') && version_compare(phpversion(), '7.1', '<')) {
        $class = "error";
        $message = sprintf(__('Mcrypt extension is missing. Please, ask your hosting provider to enable it.', 'wc_epic_payment_gateway'), '?ignore_epic_sha256_notice=0');
        echo "<div class=\"$class\"> <p>$message</p></div>";
    } else if (!function_exists('openssl_encrypt') && version_compare(phpversion(), '7.1', '>=')) {
        $class = "error";
        $message = sprintf(__('php_openssl extension is missing. Please, ask your hosting provider to enable it.', 'wc_epic_payment_gateway'), '?ignore_epic_sha256_notice=0');
        echo "<div class=\"$class\"> <p>$message</p></div>";
    }
}

add_action('admin_notices', 'wc_myepic_payment_gateway_admin_notice');
function wc_myepic_payment_gateway_admin_notice()
{
    global $current_user;
    $user_id = $current_user->ID;

    if (!get_user_meta($user_id, 'ignore_epic_sha256_notice')) {
        $class = "updated";
        $message = sprintf(__('Please, get a new SHA256 key from your TPV and enter it in the plugin configuration. | <a href="%1$s">Hide Notice</a>', 'wc_epic_payment_gateway'), '?ignore_epic_sha256_notice=0');
        echo "<div class=\"$class\"> <p>$message</p></div>";
    }
}

add_action('admin_init', 'wc_myepic_payment_gateway_ignore_notice');
function wc_myepic_payment_gateway_ignore_notice()
{
    global $current_user;
    $user_id = $current_user->ID;
    if (isset ($_GET ['ignore_epic_sha256_notice']) && '0' == $_GET ['ignore_epic_sha256_notice']) {
        add_user_meta($user_id, 'ignore_epic_sha256_notice', 'true', true);
    }
}

function init_wc_myepic_payment_gateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    include_once('fileupload.php');
    include_once('keyset.php');

    /**
     * Epic Standard Payment Gateway
     *
     * Provides a Epic Standard Payment Gateway.
     *
     * @class        WC_MyEpic
     * @extends        WC_Payment_Gateway
     * @version        1.0
     * @package
     * @author        Jesús Ángel del Pozo Domínguez
     */
    class WC_MyEpic extends WC_Payment_Gateway
    {

        /**
         * Constructor for the gateway.
         *
         * @access public
         * @return void
         */
        public function __construct()
        {
            global $woocommerce;

            $this->id = 'myepic';
            // Thank you @oscarestepa for this line
            $this->icon = apply_filters('wc_epic_icon', plugins_url('/assets/images/icons/epic.png', __FILE__));
            $this->ipgicon = apply_filters('wc_epic_icon', plugins_url('/assets/images/icons/ipg.png', __FILE__));
            $this->certurl = apply_filters('wc_epic_icon', plugins_url('/keystore/', __FILE__));
            $this->has_fields = false;
            $this->method_title = __('Epic Payment Gateway', 'wc_epic_payment_gateway');
            $this->method_description = __('Pay with credit card using Epic IPG', 'wc_epic_payment_gateway');

            // Set up localisation
            $this->load_plugin_textdomain();

            // Load the form fields.
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            // Define user set variables
            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->mode = $this->settings['mode'];
            $this->dateofregistry = $this->settings['dateofregistry'];
            $this->merchantId = $this->settings['merchantId'];
            $this->terminalId = $this->settings['terminalId'];
            $this->byteSignedDataString = $this->settings['byteSignedDataString'];
            $this->signature = $this->settings['signature'];
            $this->currencyCode = $this->settings['currencyCode'];
            $this->cardtype = $this->settings['cardtype'];
            $this->debug = $this->settings['debug'];


            // Logs
            if ('yes' == $this->debug) {
                if (version_compare(WOOCOMMERCE_VERSION, '2.0', '<')) {
                    $this->log = $woocommerce->logger();
                } else {
                    $this->log = new WC_Logger();
                }
            }

            // Actions
            if (version_compare(WOOCOMMERCE_VERSION, '2.0', '<')) {
                // Check for gateway messages using WC 1.X format
                add_action('init', array($this, 'check_notification'));
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            } else {
                // Payment listener/API hook (WC 2.X)
                add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_notification'));
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            }
            add_action('woocommerce_receipt_myepic', array($this, 'receipt_page'));

            if (!$this->is_valid_for_use()) $this->enabled = false;
        }

        /**
         * Localisation.
         *
         * @access public
         * @return void
         */
        function load_plugin_textdomain()
        {
            // Note: the first-loaded translation file overrides any following ones if the same translation is present
            $locale = apply_filters('plugin_locale', get_locale(), 'woocommerce');
            $variable_lang = (get_option('woocommerce_informal_localisation_type') == 'yes') ? 'informal' : 'formal';
            load_textdomain('wc_epic_payment_gateway', WP_LANG_DIR . '/wc_epic_payment_gateway/wc_epic_payment_gateway-' . $locale . '.mo');
            load_plugin_textdomain('wc_epic_payment_gateway', false, dirname(plugin_basename(__FILE__)) . '/languages/' . $variable_lang);
            load_plugin_textdomain('wc_epic_payment_gateway', false, dirname(plugin_basename(__FILE__)) . '/languages');
        }


        /**
         * Check if this gateway is enabled and available in the user's country
         *
         * @access public
         * @return bool
         */
        function is_valid_for_use()
        {
            //if (!in_array(get_woocommerce_currency(), array('AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP', 'RMB'))) return false;

            return true;
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @since 1.0.0
         */
        public function admin_options()
        {

            ?>
            <img src=" <?php echo $this->ipgicon ?> " width="100" height="auto" alt="Epic logo"
                 style="background: white;padding: 7px;border: 1px solid #ababab"/>
            <h3 style="color:#0677be"><?php _e('Epic - Internet Payment Gateway', 'wc_epic_payment_gateway'); ?></h3>
            <p><?php _e('Epic works by sending the user to Epic to enter their payment information.', 'wc_epic_payment_gateway'); ?></p>
            <!--                    <p>--><?php //_e( 'You\'ll find previous version (SHA1) <a href="https://github.com/jesusangel/wc-sermepa/archive/88112586ed7b4a90fe55d20f70fcea169c046c0c.zip" target="_blank">here</a>', 'wc_epic_payment_gateway' );
            ?><!--</p>-->

            <?php if ($this->is_valid_for_use()) : ?>
            <table class="form-table">
                <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html();
                ?>
            </table>
            <!--/.form-table-->
        <?php else : ?>
            <div class="inline error">
                <p>
                    <strong><?php _e('Gateway Disabled', 'wc_epic_payment_gateway'); ?></strong>: <?php _e('Epic does not support your store currency.', 'wc_epic_payment_gateway'); ?>
                </p>
            </div>

        <?php
        endif;
        }


        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        function init_form_fields()
        {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'wc_epic_payment_gateway'),
                    'type' => 'checkbox',
                    'description' => __('Enable/Disable payment method.', 'wc_epic_payment_gateway'),
                    'desc_tip' => true,
                    'label' => __('Enable IPG', 'wc_epic_payment_gateway'),
                    'default' => 'yes'
                ),
                'mode' => array(
                    'title' => __('Mode', 'wc_epic_payment_gateway'),
                    'type' => 'select',
                    'label' => __('Mode', 'wc_epic_payment_gateway'),
                    'options' => array(
                        'T' => __('Test', 'wc_epic_payment_gateway'),
                        'D' => __('Demo', 'wc_epic_payment_gateway'),
                        'L' => __('Live', 'wc_epic_payment_gateway')
                    ),
                    'description' => __('Mode: test or live', 'wc_epic_payment_gateway'),
                    'desc_tip' => true,
                    'default' => 'T'
                ),
                'title' => array(
                    'title' => __('Title', 'wc_epic_payment_gateway'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'wc_epic_payment_gateway'),
                    'desc_tip' => true,
                    'default' => __('Epic IPG', 'wc_epic_payment_gateway')
                ),
                'description' => array(
                    'title' => __('Description', 'wc_epic_payment_gateway'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'wc_epic_payment_gateway'),
                    'desc_tip' => true,
                    'default' => __('Payment gateway with epic credit card.', 'wc_epic_payment_gateway')
                ),

                // Merchant ID
                'merchantId' => array(
                    'title' => __('Merchant ID', 'woo_epicpay'),
                    'type' => 'text',
                    'description' => __('Given to Merchant by epicpay'),
                    'desc_tip' => true
                ),
                //terminalId
                'terminalId' => array(
                    'title' => __('Terminal ID', 'woo_epicpay'),
                    'type' => 'text',
                    'description' => __('Given to Merchant by epicpay'),
                    'desc_tip' => true
                ),

                // Date of Registry
                'dateofregistry' => array(
                    'title' => __('Date of Registry', 'woo_epicpay'),
                    'type' => 'date',
                    'description' => __('Given to Merchant by epicpay'),
                    'desc_tip' => true
                ),
                'byteSignedDataString' => array(
                    'title' => __('Byte Signed Data String', 'woo_epicpay'),
                    'type' => 'text',
                    'description' => __('Given to Merchant by epicpay'),
                    'desc_tip' => true
                ),
                // Signature
                'signature' => array(
                    'title' => __('Signature', 'woo_epicpay'),
                    'type' => 'text',
                    'description' => __('Given to Merchant by epicpay'),
                    'desc_tip' => true
                ),
                'cardtype' => array(
                    'title' => __('Card Type', 'wc_epic_payment_gateway'),
                    'type' => 'select',
                    'label' => __('Card Type', 'wc_epic_payment_gateway'),
                    'options' => array(
                        'VISA' => __('Visa', 'wc_epic_payment_gateway'),
                        'MASTER' => __('Master', 'wc_epic_payment_gateway'),
                        'AMEX' => __('American Express', 'wc_epic_payment_gateway')
                    ),
                    'description' => __('Select card association', 'wc_epic_payment_gateway'),
                    'desc_tip' => true,
                    'default' => 'T'
                ),
                // Currency Code
                'currencyCode' => array(
                    'title' => __('Currency Code', 'woo_epicpay'),
                    'type' => 'select',
                    'options' => array(
                        '144' => __('LKR (Srilankan Rupee)', 'wc_epic_payment_gateway'),
                        '155' => __('USD (US Dollar)', 'wc_epic_payment_gateway'),
                        '166' => __('EURO (Euro)', 'wc_epic_payment_gateway')
                    ),
                    'description' => __('Please enter your epic currency identifier; this is needed in order to take payment', 'woo_epicpay'),
                    'desc_tip' => true,
                    'default' => '144'
                ),
                // file upload
                'certfile' => array(
                    'title' => __('Certificate File', 'woo_epicpay'),
                    'type' => 'file',
                    'custom_attributes' => array('accept' => '.cer'),
                    'description' => __('Upload your certificate file that provides your service provider. (.cer file)', 'woo_epicpay'),
                    'desc_tip' => false
                ),
                'testing' => array(
                    'title' => __('Gateway Testing', 'wc_redsys_payment_gateway'),
                    'type' => 'title',
                    'description' => ''
                ),
                'debug' => array(
                    'title' => __('Debug Log', 'wc_redsys_payment_gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable logging', 'wc_redsys_payment_gateway'),
                    'description' => sprintf(__('Log Epic events, inside %s', 'wc_redsys_payment_gateway'), wc_get_log_file_path('epic-ipg')),
                    'default' => 'no'
                )
//                    'skip_checkout_form' => array(
//                        'title' => __( 'Skip checkout form', 'wc_redsys_payment_gateway' ),
//                        'type' => 'checkbox',
//                        'description' => __( 'Skip the last form of the checkout process and redirect into the payment gateway (requires Javascript).', 'wc_redsys_payment_gateway' ),
//                        'default' => 'yes'
//                    ),
            );

        }


        /**
         * Get Epic Args for passing to the TPV server
         *
         * @access public
         * @param mixed $order
         * @return array
         */
        function get_epic_args($order)
        {
            // FIX by SUGO
            $order_id = version_compare(WC_VERSION, '2.7', '<') ? $order->id : $order->get_id();
            $unique_order_id = str_pad($order_id, 8, '0', STR_PAD_LEFT) . date('is');

            // Customize order code
            $unique_order_id = apply_filters('wc_myepic_merchant_order_encode', $unique_order_id, $order_id);

            if ('yes' == $this->debug) {
                $this->log->add('epic', 'Generating payment form for order #' . $order_id . '. Notify URL: ');
            }

            $products = '';
            if (version_compare(WOOCOMMERCE_VERSION, '2.1', '>')) {
                if (is_array($cart_contents = WC()->cart->cart_contents)) {
                    foreach ($cart_contents as $cart_content) {
                        if (!empty($products)) {
                            $separator = '/';
                        } else {
                            $separator = '';
                        }
                        $product_title = version_compare(WC_VERSION, '2.7', '<') ? $cart_content['data']->post->post_title : $cart_content['data']->get_title();
                        $products .= $separator . $cart_content['quantity'] . 'x' . $product_title;
                    }
                }
            } else {
                $products = __('Online order', 'wc_epic_payment_gateway');
            }

            // ================  Certificate certify process begin ===================

            // merchant id
            $_mid = $this->merchantId;
            // pem file url
            $_url = $this->certurl;
            //get private key
            $pvt_key = getPVTKey(getUrlFromContext($_url,$_mid));
            //get shared key for Symmetric key encryption
            $key = generateKey($pvt_key);
            //encryption source
            $source = $_mid;
            // encrypted mid
            $encrypted_val = encrypt($source, $key);
            // digitally signed data --> mid
            $dsdata = $source;
            // byte string
            $byteSignedData = digitalsign($dsdata, $pvt_key,$_mid,$_url);

            var_dump("Key              --> " . $key);
            var_dump("Source           --> " . $source);
            var_dump("Encrypted Val    --> " . $encrypted_val);
            var_dump("Byte Signed Data --> " . $byteSignedData);

            //================= Certificate certify process end ======================


            $epic_args = array(

                'terminalId' => $this->terminalId,
                'merchantId' => $this->merchantId,
                'amount' => $order->order_total,
                'orderid' => $order->id,
                'currencyCode' => $this->currencyCode,
                //must be convert to the needed format
                'dateofregistry' => $this->dateofregistry,
                // implemented generate random number
                'refno' => "123",
                // normal or standard
                'merchantType' => "1234",
                // implemented generate random number
                'txnRefNo' => "88",
                //must be implemented
                'emerchantId' => "B035BA39CCFD9E660C0033597C869237",
                'signature' => $this->merchantId,
                //must be implemented
                'key' => "",
                //must be implemented
                'byteSignedDataString' => "A598283A30FAA41786CD1EE7981B7D69DF801572B78B4C30A9EA87406AC26908E2ACBF0D59E720D9A96DD3993C4FAEA0D5A064A36C17971BF2772BC7A501D69BD896F17A25F005931E1D7E782E25BBC6346C77D9615A865CFE50B81689570169AE2346D4E666232309022C94754F55A88C58E5C58948969FDAE0867EF9B99FAE",
                // can get url select
                'url' => "http://localhost:7001/EPIC_IPG/IPGMerchantAddOnServlet",
                //card type
                'radio' => $this->cardtype


            );

            $epic_args = apply_filters('woocommerce_epic_args', $epic_args);

            return $epic_args;
        }

        /**
         * Generate the epic button link
         *
         * @access public
         * @param mixed $order_id
         * @return string
         */
        function generate_epic_form($order_id)
        {
            global $woocommerce;

            $order = new WC_Order($order_id);

            switch ($this->mode) {
                case 'T' :
//						$epic_addr = 'https://sis-t.epic.es:25443/sis/realizarPago/utf-8';
                    $epic_addr = 'http://localhost:7001/EPIC_IPG/IPGMerchantAddOnServlet';

                    break;
                case 'L':
                    $epic_addr = 'http://localhost:7001/EPIC_IPG/IPGMerchantAddOnServletLIVE';
                    break;
                case 'D':
                    $epic_addr = 'http://localhost:7001/EPIC_IPG/IPGMerchantAddOnServletLIVE';
                    break;
                default:
                    $epic_addr = 'http://localhost:7001/EPIC_IPG/IPGMerchantAddOnServlet';
                    break;
            }

            try {
                $epic_args = $this->get_epic_args($order);
            } catch (Exception $e) {
                if ('yes' == $this->debug) {
                    $this->log->add('epic', 'Error generating payment form ' . $e->getMessage());
                }
                return $e->getMessage();
            }

            if ('yes' == $this->debug) {
                $this->log->add('epic', 'Sending data to Epic ' . print_r($epic_args, true));
            }

            $epic_fields_array = array();

            foreach ($epic_args as $key => $value) {
                $epic_fields_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
            }

//                if ( empty( $this->settings['skip_checkout_form'] ) || $this->settings['skip_checkout_form'] != 'no' ) {
            if ($this->mode == "T") {

                if (version_compare(WOOCOMMERCE_VERSION, '2.2.3', '<')) {
                    $loader_html = '<img src="' . esc_url(apply_filters('woocommerce_ajax_loader_url', $woocommerce->plugin_url() . '/assets/images/ajax-loader.gif')) . '" alt="' . __('Redirecting&hellip;', 'wc_epic_payment_gateway') . '" style="float: left; margin-right: 10px;" />';
                } else {
                    $loader_html = '<div class="woocommerce" style="width: 2em; height: 2em; position: relative; float: left; margin-right: 15px;"><div class="loader"></div></div>';
                }

                $script = '
                        if (jQuery.fn.block) {
                            jQuery("body").block({
                                message: \'' . $loader_html . esc_js(__('Thank you for your order. You are being redirected to the payment gateway.', 'wc_epic_payment_gateway')) . '\',
                                overlayCSS:
                                {
                                    background: "#fff",
                                    opacity: 0.6
                                },
                                css: {
                                    padding:        20,
                                    textAlign:      "center",
                                    color:          "#555",
                                    border:         "3px solid #aaa",
                                    backgroundColor:"#fff",
                                    cursor:         "wait",
                                    lineHeight:		"32px"
                                }
                            });
                        }
                        jQuery(document).ready(function(){
                            jQuery("#epic_payment_form").submit();
                        });
                    ';

                if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
                    $woocommerce->add_inline_js($script);
                } else {
                    wc_enqueue_js($script);
                }

                return '<form action="' . esc_url($epic_addr) . '" method="post" id="epic_payment_form" target="_top">
						' . implode('', $epic_fields_array) . '
						<input type="submit" class="button button-alt" id="submit_epic_payment_form" value="' . __('Pay via Epic', 'wc_epic_payment_gateway') . '" /> 
						<a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'wc_epic_payment_gateway') . '</a>
					</form>';

            } else {
                return '<form action="' . esc_url($epic_addr) . '" method="post" id="epic_payment_form" target="_top">
						' . implode('', $epic_fields_array) . '
						<img src="' . $this->ipgicon . '" width="300" height="auto" alt="Epic logo" style="background: white;padding: 7px;border: 1px solid #ababab"/>
					</form>';
            }


        }

        /**
         * Process the payment and return the result
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        function process_payment($order_id)
        {

            $order = new WC_Order($order_id);

            if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
                $redirect_url = add_query_arg('order',$order_id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))));
            } else {
                $redirect_url = $order->get_checkout_payment_url(true);
            }

            return array(
                'result' => 'success',
                'redirect' => $redirect_url
            );
        }

        /**
         * Output for the order received page.
         *
         * @access public
         * @return void
         */
        function receipt_page($order)
        {

//            echo '<p>' . __('Thank you for your order, please click the button below to pay with Epic.', 'wc_epic_payment_gateway') . '</p>';
            echo '<p>' . __('Thank you for connecting with epic IPG', 'wc_epic_payment_gateway') . '</p>';

            echo $this->generate_epic_form($order);

        }

        /**
         * Check for Epic notification
         *
         * @access public
         * @return void
         */
        function check_notification()
        {
            global $woocommerce;

            if ('yes' == $this->debug) {
                $this->log->add('epic', 'Checking notification is valid...');
            }

            if (!empty($_REQUEST)) {
                if (!empty($_POST) && array_key_exists('ds_signature', array_change_key_case($_POST, CASE_LOWER))) {

                    @ob_clean();

                    // Get received values from post data
                    $received_values = (array)stripslashes_deep($_POST);

                    if ('yes' == $this->debug) {
                        $this->log->add('epic', 'Received data: ' . print_r($received_values, true));
                    }

                    $received_signature = $_POST['Ds_Signature'];
                    $version = $_POST['Ds_SignatureVersion'];
                    $encoded_data = $_POST['Ds_MerchantParameters'];

                    $data = base64_decode(strtr($encoded_data, '-_', '+/'));
                    $data = json_decode($data, true);

                    try {
                        $calculated_signature = $this->generateResponseSignature($this->secret_key, $encoded_data);
                    } catch (Exception $e) {
                        if ('yes' == $this->debug) {
                            $this->log->add('epic', 'Error while validating notification from Epic: ' . $e->getMessage());
                        }
                        wp_die();
                    }

                    $received_amount = $data['Ds_Amount'];
                    $order_id = substr($data['Ds_Order'], 0, 8);
                    $fuc = $data['Ds_MerchantCode'];
                    $currency = $data['Ds_Currency'];
                    $response = $data['Ds_Response'];
                    $auth_code = $data['Ds_AuthorisationCode'];

                    // Reverse order code customization (@enbata)
                    $order_id = apply_filters('wc_myepic_merchant_order_decode', $order_id, $data['Ds_Order']);

                    // check to see if the response is valid
                    if ($received_signature === $calculated_signature
                        && $this->checkResponse($response)
                        && $this->checkAmount($received_amount)
                        && $this->checkOrderId($order_id)
                        && $this->checkCurrency($currency)
                        && $this->checkFuc($fuc)
                    ) {
                        if ('yes' == $this->debug) {
                            $this->log->add('epic', 'Received valid notification from Epic. Payment status: ' . $response);
                        }

                        $order = new WC_Order($order_id);

                        // We are here so lets check status and do actions
                        $response = (int)$response;
                        if ($response < 101 && $this->checkAuthorisationCode($auth_code)) {    // Completed

                            // Check order not already completed
                            if ($order->status == 'completed') {
                                if ('yes' == $this->debug) {
                                    $this->log->add('epic', 'Aborting, Order #' . $order_id . ' is already complete.');
                                }
                                wp_die();
                            }


                            // Validate Amount
                            $order_amount = $order->get_total();
                            if ($this->currency_id == 978) {
                                $received_amount = $received_amount / 100;    // For Euros, epic assumes that last two digits are decimals
                            }

                            if ($order_amount != $received_amount) {

                                if ($this->debug == 'yes') {
                                    $this->log->add('epic', "Payment error: Order's ammount {$order_amount} do not match received amount {$received_amount}");
                                }

                                // Put this order on-hold for manual checking
                                $order->update_status('on-hold', sprintf(__('Validation error: Epic amounts do not match (amount %s).', 'wc_epic_payment_gateway'), $received_amount));

                                wp_die();
                            }

                            // Store payment Details
                            if (!empty($data['Ds_Date']))
                                update_post_meta($order_id, 'Payment date', $data['Ds_Date']);
                            if (!empty($data['Ds_Hour']))
                                update_post_meta($order_id, 'Payment hour', $data['Ds_Hour']);
                            if (!empty($data['Ds_AuthorisationCode']))
                                update_post_meta($order_id, 'Authorisation code', $data['Ds_AuthorisationCode']);
                            if (!empty($data['Ds_Card_Country']))
                                update_post_meta($order_id, 'Card country', $data['Ds_Card_Country']);
                            if (!empty($data['last_name']))
                                update_post_meta($order_id, 'Consumer language', $data['Ds_ConsumerLanguage']);
                            if (!empty($data['Ds_Card_Type']))
                                update_post_meta($order_id, 'Card type', $data['Ds_Card_Type'] == 'C' ? 'Credit' : 'Debit');

                            // Payment completed
                            $order->add_order_note(__('Epic payment completed', 'wc_epic_payment_gateway'));
                            $order->payment_complete();

                            // Set order as completed if user did set up it
                            if ('Y' == $this->set_completed) {
                                $order->update_status('completed');
                            }

                            if ('yes' == $this->debug) {
                                $this->log->add('epic', 'Payment complete.');
                            }
                        } else if ($response >= 101 && $response <= 202) {
                            // Order failed
                            $message = sprintf(__('Payment error: code: %s.', 'wc_epic_payment_gateway'), $response);
                            $order->update_status('failed', $message);
                            if ($this->debug == 'yes')
                                $this->log->add('epic', "{$message}");
                        } else if ($response == 900) {
                            // Transacción autorizada para devoluciones y confirmaciones
                            /*
                            // Only handle full refunds, not partial
                            if ($order->get_total() == ($posted['mc_gross']*-1)) {

                                // Mark order as refunded
                                $order->update_status('refunded', sprintf(__('Payment %s via IPN.', 'wc_epic_payment_gateway'), strtolower($posted['payment_status']) ) );

                                $mailer = $woocommerce->mailer();

                                $mailer->wrap_message(
                                        __('Order refunded/reversed', 'wc_epic_payment_gateway'),
                                        sprintf(__('Order %s has been marked as refunded - Epic reason code: %s', 'wc_epic_payment_gateway'), $order->get_order_number(), $posted['reason_code'] )
                                );

                                $mailer->send( get_option('woocommerce_new_order_email_recipient'), sprintf( __('Payment for order %s refunded/reversed', 'wc_epic_payment_gateway'), $order->get_order_number() ), $message );

                                }
                                */
                        } else if ($response == 912 || $response == 9912) {
                            // Order failed
                            $message = sprintf(__('Payment error: bank unavailable.', 'wc_epic_payment_gateway'));
                            $order->update_status('failed', $message);
                            if ($this->debug == 'yes')
                                $this->log->add('epic', "{$message}");
                        } else {
                            // Order failed
                            $message = sprintf(__('Payment error: code: %s.', 'wc_epic_payment_gateway'), $response);
                            $order->update_status('failed', $message);
                            if ($this->debug == 'yes')
                                $this->log->add('epic', "{$message}");
                        }
                    } else {
                        if ('yes' == $this->debug) {
                            $this->log->add('epic', "Received invalid notification from Epic.\nSignature: {$received_signature}\nVersion: {$version}\nData: " . print_r($data, true));
                        }

                        //$order->update_status('cancelled', __( 'Awaiting REDSYS payment', 'wc_epic_payment_gateway' ));

                        if (version_compare(WOOCOMMERCE_VERSION, '2.0', '<')) {
                            $woocommerce->cart->empty_cart();
                        } else {
                            WC()->cart->empty_cart();
                        }
                    }

                }
            }
        }

        /**
         * Converts array to JSON and encodes string to base64
         *
         * @param array $data Merchant data
         * @return string B64(json($data))
         */
        function encodeMerchantData($data)
        {
            return base64_encode(json_encode($data));
        }

        function generateMerchantSignature($key, $b64_parameters, $order_id)
        {
            $key = base64_decode($key);
            $key = $this->encrypt_3DES($order_id, $key);
            $mac256 = $this->mac256($b64_parameters, $key);
            return base64_encode($mac256);
        }

        function generateResponseSignature($key, $b64_data)
        {
            $key = base64_decode($key);
            $data_string = base64_decode(strtr($b64_data, '-_', '+/'));
            $data = json_decode($data_string, true);
            $key = $this->encrypt_3DES($this->getOrderNotified($data), $key);
            $mac256 = $this->mac256($b64_data, $key);
            return strtr(base64_encode($mac256), '+/', '-_');
        }

        function getOrderNotified($data)
        {
            $order_id = "";
            if (empty($data['Ds_Order'])) {
                $order_id = $data['DS_ORDER'];
            } else {
                $order_id = $data['Ds_Order'];
            }
            return $order_id;
        }

        function mac256($b64_data, $key)
        {
            return hash_hmac('sha256', $b64_data, $key, true);
        }

        /**
         *
         * @link https://github.com/eusonlito/epic-TPV/issues/14
         *
         * @param $message
         * @param $key
         * @return bool|null|string
         *
         *
         * @throws Exception
         */
        function encrypt_3DES($message, $key)
        {
            $ciphertext = null;

            if (function_exists('mcrypt_encrypt') && version_compare(phpversion(), '7.1', '<')) {

                $bytes = array(0, 0, 0, 0, 0, 0, 0, 0); //byte [] IV = {0, 0, 0, 0, 0, 0, 0, 0}
                $iv = implode(array_map("chr", $bytes));
                $ciphertext = mcrypt_encrypt(MCRYPT_3DES, $key, $message, MCRYPT_MODE_CBC, $iv);

            } else if (function_exists('openssl_encrypt') && version_compare(phpversion(), '7.1', '>=')) {

                $l = ceil(strlen($message) / 8) * 8;
                $ciphertext = substr(openssl_encrypt($message . str_repeat("\0", $l - strlen($message)), 'des-ede3-cbc', $key, OPENSSL_RAW_DATA, "\0\0\0\0\0\0\0\0"), 0, $l);

            } else if (!function_exists('openssl_encrypt') && version_compare(phpversion(), '7.1', '>=')) {

                throw new Exception(__('php_openssl extension is not available in this server', 'wc_epic_payment_gateway'));
            } else if (!function_exists('mcrypt_encrypt') && version_compare(phpversion(), '7.1', '<')) {

                throw new Exception(__('Mcrypt extension is not available in this server', 'wc_epic_payment_gateway'));
            }

            return $ciphertext;
        }

        function checkAmount($amount)
        {
            return preg_match('/^\d+$/', $amount);
        }

        function checkOrderId($order_id)
        {
            return preg_match('/^\d{1,12}$/', $order_id);
        }

        function checkFuc($codigo)
        {
            $retVal = preg_match('/^\d{2,9}$/', $codigo);
            if ($retVal) {
                $codigo = str_pad($codigo, 9, '0', STR_PAD_LEFT);
                $fuc = intval($codigo);
                $check = substr($codigo, -1);
                $fucTemp = substr($codigo, 0, -1);
                $acumulador = 0;
                $tempo = 0;

                for ($i = strlen($fucTemp) - 1; $i >= 0; $i -= 2) {
                    $temp = intval(substr($fucTemp, $i, 1)) * 2;
                    $acumulador += intval($temp / 10) + ($temp % 10);
                    if ($i > 0) {
                        $acumulador += intval(substr($fucTemp, $i - 1, 1));
                    }
                }
                $ultimaCifra = $acumulador % 10;
                $resultado = 0;
                if ($ultimaCifra != 0) {
                    $resultado = 10 - $ultimaCifra;
                }
                $retVal = $resultado == $check;
            }
            return $retVal;
        }

        function checkCurrency($currency)
        {
            return preg_match("/^\d{1,3}$/", $currency);
        }

        function checkResponse($response)
        {
            return preg_match("/^\d{1,4}$/", $response);
        }

        function checkAuthorisationCode($auth_code)
        {
            return preg_match("/^\w{1,6}$/", $auth_code);
        }
    }

    /**
     * Add the gateway to WooCommerce
     *
     * @access public
     * @param array $methods
     * @package
     * @return array
     */
    function add_myepic_gateway($methods)
    {
        $methods[] = 'WC_MyEpic';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_myepic_gateway');
}


/**
 *  Add settings to plugins section
 *
 * @access public
 * @param $actions
 * @param $plugin_file
 * @return array
 */
add_filter('plugin_action_links', 'myepic_add_action_plugin', 10, 5);
function myepic_add_action_plugin($actions, $plugin_file)
{
    static $plugin;

    if (!isset($plugin))
        $plugin = plugin_basename(__FILE__);
    if ($plugin == $plugin_file) {

        $settings = array(
            'settings' => '<a href="admin.php?page=wc-settings&tab=checkout&section=myepic">' . __('Settings') . '</a>'
//            'contact' => '<a href="http://www.epictechnology.lk/">' . __('Contact') . '</a>'
        );

        $actions = array_merge($settings, $actions);
    }

    return $actions;
}