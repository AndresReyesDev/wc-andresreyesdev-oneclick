<?php
require_once WP_PLUGIN_DIR . "/wc-andresreyesdev-oneclick/vendor/autoload.php";

use Transbank\Webpay\Configuration;
use Transbank\Webpay\Webpay;

/*
 * Plugin Name: WooCommerce Transbank OneClick Payment Gateway
 * Plugin URI: https://andres.reyes.dev
 * Description: Pay using Transbank OneClick Service
 * Author: Andrés Reyes Galgani
 * Author URI: https://andres.reyes.dev
 * Version: 2020.09.25
 */


add_filter('woocommerce_payment_gateways', 'andresreyesdev_add_gateway_class');
function andresreyesdev_add_gateway_class($gateways)
{
    $gateways[] = 'WC_AndresReyesDev_Gateway';
    return $gateways;
}

add_action('plugins_loaded', 'andresreyesdev_init_gateway_class');
function andresreyesdev_init_gateway_class()
{
    class WC_AndresReyesDev_Gateway extends WC_Payment_Gateway_CC
    {
        private static $URL_RETURN;
        private static $URL_FINAL;

        public function __construct()
        {
            $this->id = 'andresreyesdev_oneclick';
            $this->icon = plugin_dir_url(__FILE__) . 'assets/img/oneclick.png';
            $this->has_fields = false;
            $this->method_title = 'Webpay OneClick';
            $this->method_description = 'Pay with Transbank OneClick';
            $this->notify_url = add_query_arg('wc-api', 'WC_Gateway_' . $this->id, home_url('/'));
            $this->new_method_label = __('Use a new card', 'woocommerce');

            $this->supports = array(
                'products',
                'subscriptions',
                'subscription_cancellation',
                'subscription_suspension',
                'subscription_reactivation',
                'subscription_amount_changes',
                'subscription_date_changes',
                'subscription_payment_method_change',
                'subscription_payment_method_change_customer',
                'subscription_payment_method_change_admin',
                'multiple_subscriptions',
                'tokenization',

            );

            $this->init_form_fields();

            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

            self::$URL_RETURN = home_url('/') . '?wc-api=WC_Gateway_andresreyesdev_oneclick';
            self::$URL_FINAL = '_URL_';

            add_action('woocommerce_api_wc_gateway_andresreyesdev_oneclick', array($this, 'return_handler'));
            //add_action('woocommerce_scheduled_subscription_payment', __CLASS__, '::scheduled_subscription_payment', 10, 1);
            add_action('woocommerce_scheduled_subscription_payment_andresreyesdev_oneclick', __CLASS__. '::scheduled_subscription_payment', 10, 2);
            //add_action('woocommerce_subscriptions_before_payment_retry', array($this, 'testing_method'));
            //add_action('wcs_default_retry_rules', array($this, 'retryRules'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'label' => 'Enable Webpay OneClick',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'El nombre del medio de pago que es visible al finalizar la compra.',
                    'default' => 'Webpay OneClick',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'La descripción del medio de pago que es visible al finalizar la compra.',
                    'default' => 'Paga con tu tarjeta de crédito asociada a Transbank.',
                )
            );
        }

        public function payment_fields()
        {
            $description = $this->get_description();

            if ($description) {
                echo wpautop(wptexturize(trim($description)));
            }

            if ($this->supports('tokenization') && is_checkout()) {
                $this->tokenization_script();
                $this->saved_payment_methods();
            }
        }

        public function saved_payment_methods()
        {
            if (count($this->get_tokens()) == 0) {
                $html = '<div style="background-color: #ffd1d1; padding: 10px; border-radius: 5px; margin-top: 15px; border: 2px solid #ff0000;">Para usar Webpay OneClick debe asociar su tarjeta <u>previamente</u> haciendo <a href="' . wc_get_endpoint_url('payment-methods', '', wc_get_page_permalink('myaccount')) . '">clic aquí</a></div>';
            } else {
                $html = '<ul class="woocommerce-SavedPaymentMethods wc-saved-payment-methods" data-count="' . esc_attr(count($this->get_tokens())) . '">';

                foreach ($this->get_tokens() as $token) {
                    $html .= $this->get_saved_payment_method_option_html($token);
                }

                $html .= '</ul>';
            }

            echo apply_filters('wc_payment_gateway_form_saved_payment_methods_html', $html, $this);
        }

        public function payment_scripts()
        {
            echo "<style>.payment_method_andresreyesdev_oneclick img {height: 40px !important;}</style>";
        }

        public function return_handler()
        {
            @ob_clean();
            global $wpdb;
            $token_ws = isset($_POST["TBK_TOKEN"]) ? $_POST["TBK_TOKEN"] : null;

            $configuration = Configuration::forTestingWebpayOneClickNormal();
            $transaction = (new Webpay($configuration))->getOneClickTransaction();

            $result = $transaction->finishInscription($token_ws);

            if (is_object($result) && isset($result->responseCode)) {
                if (0 == $result->responseCode) {

                    $table_name = $wpdb->prefix . "andresreyesdev_oneclick";
                    $token_row = $wpdb->get_row("SELECT * FROM $table_name WHERE token = '$token_ws'");

                    if ($token_row->user_id != get_current_user_id()) {
                        wc_add_notice('Error entre tarjeta de WebPay OneClick y el Usuario.<br/>', 'error');
                        wp_redirect(wc_get_endpoint_url('payment-methods', '', wc_get_page_permalink('myaccount')));
                        exit();
                    } else {
                        $token = new WC_Payment_Token_CC();

                        $token->set_token($result->tbkUser);

                        $token->set_card_type($result->creditCardType);
                        $token->set_last4($result->last4CardDigits);
                        $token->set_expiry_month('12');
                        $token->set_expiry_year(date('Y') + 3);

                        $token->set_gateway_id($this->id);

                        $token->set_user_id(get_current_user_id());

                        $token->save();
                        wc_add_notice('Tarjeta agregada exitosamente.<br/>', 'success');
                        wp_redirect(wc_get_endpoint_url('payment-methods', '', wc_get_page_permalink('myaccount')));
                    }

                    exit();
                } else {
                    wc_add_notice('Ocurrió un error al asociar la tarjeta a WebPay OneClick. Por favor, comuníquese con su emisor.<br/>', 'error');
                    wp_redirect(wc_get_endpoint_url('payment-methods', '', wc_get_page_permalink('myaccount')));
                    exit();
                }
            }
            exit();
        }

        /*
         * We're processing the payments here, everything about it is in Step 5
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (isset($_POST['wc-andresreyesdev_oneclick-payment-token']) && 'new' !== $_POST['wc-andresreyesdev_oneclick-payment-token']) {
                $token_id = wc_clean($_POST['wc-andresreyesdev_oneclick-payment-token']);
                $token = WC_Payment_Tokens::get($token_id);
                if ($token->get_user_id() !== get_current_user_id()) {
                    wc_add_notice(__('Please make sure your card details have been entered correctly and that your browser supports JavaScript.', 'woocommerce'), 'error');
                    return;
                }

                $configuration = Configuration::forTestingWebpayOneClickNormal();
                $transaction = (new Webpay($configuration))->getOneClickTransaction();

                $output = $transaction->authorize($order_id, $token->get_token(), wp_get_current_user()->user_login, $order->get_total());

                write_log($token->get_token());

                if (is_object($output)) {
                    WC()->session->set($order->get_order_key(), $output);
                    if ($output->responseCode == 0) {
                        WC()->session->set($order->get_order_key() . "_transaction_paid", 1);
                        $order->add_order_note(__('Pago Exitoso con Webpay OneClick', 'woocommerce'));
                        $order->add_order_note(__(json_encode($output), 'woocommerce'));
                        $order->payment_complete($output->transactionId);
                        WC()->cart->empty_cart();
                        /**return [
                         * 'result' => 'success',
                         * 'redirect' => $order->get_checkout_payment_url(true)
                         * ];**/

                        return array(
                            'result' => 'success',
                            'redirect' => $this->get_return_url($order)
                        );
                    }
                }
            }
            return false;
        }

        public function scheduled_subscription_payment($amount, $order)
        {
            if ( 0 == $amount ) {
                $order->payment_complete();
                return;
            }

            $token = WC_Payment_Tokens::get_customer_default_token($order->get_customer_id());
            $user = $order->get_user();

            $configuration = Configuration::forTestingWebpayOneClickNormal();
            $transaction = (new Webpay($configuration))->getOneClickTransaction();

            $output = $transaction->authorize($order->get_id(), $token->get_token(), $user->user_login, $amount);
            write_log("Resultado: " . json_encode($output));

            if (is_object($output)) {
                if ($output->responseCode == 0) {
                    $order->payment_complete($output->transactionId);
                    $order->add_order_note(__('Suscripción — Pago Exitoso con Webpay OneClick', 'woocommerce'));
                    $order->add_order_note(__(json_encode($output), 'woocommerce'));
                    write_log("Fin Exitoso // process_subscription_payment");
                    return true;
                }
            } else {
                $order->update_status( 'failed');
                $order->add_order_note(__('Suscripción — Error en pago con Webpay OneClick', 'woocommerce'));
            }
            return false;
        }

        public function retryRules()
        {
            return array(
                array(
                    'retry_after_interval' => DAY_IN_SECONDS,
                    'email_template_customer' => 'WCS_Email_Customer_Payment_Retry',
                    'email_template_admin' => 'WCS_Email_Payment_Retry',
                    'status_to_apply_to_order' => 'pending',
                    'status_to_apply_to_subscription' => 'on-hold',
                ),
                array(
                    'retry_after_interval' => 2 * DAY_IN_SECONDS,
                    'email_template_customer' => 'WCS_Email_Customer_Payment_Retry',
                    'email_template_admin' => 'WCS_Email_Payment_Retry',
                    'status_to_apply_to_order' => 'pending',
                    'status_to_apply_to_subscription' => 'on-hold',
                ),
            );
        }

        public function redirect($url, $data)
        {
            echo "<form action='" . $url . "' method='POST' name='webpayForm'>";
            foreach ($data as $name => $value) {
                echo "<input type='hidden' name='" . htmlentities($name) . "' value='" . htmlentities($value) . "'>";
            }
            echo "</form>" . "<script language='JavaScript'>" . "document.webpayForm.submit();" . "</script>";
        }

        public function add_payment_method()
        {
            global $wpdb;
            $tokens = WC_Payment_Tokens::get_customer_tokens(get_current_user_id(), 'andresreyesdev_oneclick');

            if (empty($tokens)) {

                $current_user = wp_get_current_user();
                $returnUrl = self::$URL_RETURN;

                $configuration = Configuration::forTestingWebpayOneClickNormal();
                $transaction = (new Webpay($configuration))->getOneClickTransaction();

                $result = $transaction->initInscription($current_user->user_login, $current_user->user_email, $returnUrl);


                if (isset($result->token)) {

                    $table_name = $wpdb->prefix . 'andresreyesdev_oneclick';
                    $wpdb->insert($table_name, array(
                        'token' => $result->token,
                        'user_id' => $current_user->data->ID
                    ));


                    $url = $result->urlWebpay;
                    $token = $result->token;

                    self::redirect($url, ["TBK_TOKEN" => $token]);
                    exit;

                } else {
                    wc_add_notice('Ocurri&oacute; un error al intentar conectar con WebPay OneClick. Por favor intenta mas tarde.<br/>', 'error');
                }

            } else {
                wc_add_notice(__('Ya tiene una tarjeta activa. Elimine la anterior y cargue una nueva', 'woocommerce'), 'error');
                return;
            }

            return array(
                'result' => 'success',
                'redirect' => wc_get_endpoint_url('payment-methods'),
            );
        }
    }
}

function activate_wc_andresreyesdev_oneclick()
{
    global $wpdb;
    $table_name = $wpdb->prefix . "andresreyesdev_oneclick";
    $andresreyesdev_oneclick_db_version = '1.0.0';
    $charset_collate = $wpdb->get_charset_collate();

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            `token` text NOT NULL,
            `user_id` int(9) NOT NULL,
            PRIMARY KEY  (id)
    )    $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        add_option('andresreyesdev_oneclick_db_version', $andresreyesdev_oneclick_db_version);
    }
}

function deactivate_wc_andresreyesdev_oneclick()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'andresreyesdev_oneclick';
    $sql = "DROP TABLE IF EXISTS $table_name";
    $wpdb->query($sql);
    //delete_option("jal_db_version");
}

register_activation_hook(__FILE__, 'activate_wc_andresreyesdev_oneclick');
register_deactivation_hook(__FILE__, 'deactivate_wc_andresreyesdev_oneclick');

if (!function_exists('write_log')) {

    function write_log($log)
    {
        if (true === WP_DEBUG) {
            if (is_array($log) || is_object($log)) {
                error_log(print_r($log, true));
            } else {
                error_log($log);
            }
        }
    }

}