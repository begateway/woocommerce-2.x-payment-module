<?php
/*
Plugin Name: WooCommerce beGateway Payment Gateway
Plugin URI: https://github.com/beGateway/woocommerce-payment-module
Description: Extends WooCommerce with beGateway payment gateway.
Version: 1.0.2
Author: beGateway development team

Text Domain: woocommerce-begateway
Domain Path: /languages/

 */

//setup definitions - may not be needed but belts and braces chaps!
define('BT_BEGATEWAY_VERSION', '1.0.0');

if ( !defined('WP_CONTENT_URL') )
  define('WP_CONTENT_URL', get_option('siteurl') . '/wp-content');

if ( !defined('WP_PLUGIN_URL') )
  define('WP_PLUGIN_URL', WP_CONTENT_URL.'/plugins');

if ( !defined('WP_CONTENT_DIR') )
  define('WP_CONTENT_DIR', ABSPATH . 'wp-content');

if ( !defined('WP_PLUGIN_DIR') )
  define('WP_PLUGIN_DIR', WP_CONTENT_DIR.'/plugins');

define("BT_BEGATEWAY_PLUGINPATH", "/" . plugin_basename( dirname(__FILE__) ));

define('BT_BEGATEWAY_BASE_URL', WP_PLUGIN_URL . BT_BEGATEWAY_PLUGINPATH);

define('BT_BEGATEWAY_BASE_DIR', WP_PLUGIN_DIR . BT_BEGATEWAY_PLUGINPATH);

//go looking for woocommerce - if not found then do not allow this plugin to do anything
if(!function_exists('bt_get_plugins'))
{
  function bt_get_plugins()
  {
    if ( !is_multisite() )
      return false;

    $all_plugins = array_keys((array) get_site_option( 'active_sitewide_plugins' ));
    if (!is_array($all_plugins) )
      return false;

    return $all_plugins;
  }
}

if ( in_array( 'woocommerce/woocommerce.php', (array) get_option( 'active_plugins' )  ) || in_array('woocommerce/woocommerce.php', (array) bt_get_plugins() ) )
{
  load_plugin_textdomain('woocommerce-begateway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages');
  add_action('plugins_loaded', 'bt_begateway_go', 0);
  add_filter('woocommerce_payment_gateways', 'bt_begateway_add_gateway' );

}

require_once dirname(  __FILE__  ) . '/begateway-api-php/lib/BeGateway.php';

//Launch plugin
function bt_begateway_go()
{

  class BT_beGateway extends WC_Payment_Gateway
  {
    var $notify_url;

    public $id = 'begateway';
    public $icon;//not used
    public $has_fields = true;
    public $method_title;
    public $title;
    public $settings;

    /**
     * constructor
     *
     */
    function __construct()
    {
      global $woocommerce;
      // load form fields
      $this->init_form_fields();
      // initialise settings
      $this->init_settings();
      // variables
      $this->title                    = $this->settings['title'];
      //admin title
      if ( current_user_can( 'manage_options' ) ){
        $this->title                    = $this->settings['admin_title'];
      }

      \BeGateway\Settings::$gatewayBase = 'https://' . $this->settings['domain-gateway'];
      \BeGateway\Settings::$checkoutBase = 'https://' . $this->settings['domain-checkout'];
      \BeGateway\Settings::$shopId = $this->settings['shop-id'];
      \BeGateway\Settings::$shopKey = $this->settings['secret-key'];
      //callback URL - hooks into the WP/WooCommerce API and initiates the payment class for the bank server so it can access all functions
      $this->notify_url    = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'BT_beGateway', home_url( '/' ) ) );
      $this->notify_url    = str_replace('carts.local','webhook.begateway.com:8443', $this->notify_url);
      $this->notify_url    = str_replace('app.docker.local:8080','webhook.begateway.com:8443', $this->notify_url);

      $this->method_title             = $this->title;
      $this->description              = $this->settings['description'];
      $this->transaction_type         = $this->settings['tx-type'];
      $this->debug                    = $this->settings['debug'];
      $this->show_transaction_table   = $this->settings['show-transaction-table'] == 'yes' ? true : false;
      // Logs
      if ( 'yes' == $this->debug ){
        $this->log = new WC_Logger();
      }

      add_action('admin_menu', array($this, 'bt_admin_hide') );
      add_action('admin_notices',array($this, 'bt_admin_error') );
      add_action('admin_notices',array($this, 'bt_admin_message') );
      add_action('woocommerce_receipt_begateway', array( $this, 'receipt_page'));
      add_action('woocommerce_api_bt_begateway', array( $this, 'check_ipn_response' ) );
      add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

      // display transaction table
      if ( is_admin() && $this->show_transaction_table )
      {
        add_action( 'add_meta_boxes', array($this, 'create_order_transactions_meta_box') );
        //$this->create_order_transactions_meta_box();
      }
    } // end __construct

    public function admin_options()
    {
      echo '<h3>' . __('beGateway', 'woocommerce-begateway') . '</h3>';
      echo '<table class="form-table">';
      // generate the settings form.
      $this->generate_settings_html();
      echo '</table><!--/.form-table-->';
    } // end admin_options()


    public function init_form_fields()
    {
      // transaction options
      $tx_options = array('payment' => __('Payment', 'woocommerce-begateway'), 'authorization' => __('Authorization', 'woocommerce-begateway'));

      $this->form_fields = array(
        'enabled' => array(
          'title' => __( 'Enable/Disable', 'woocommerce-begateway' ),
          'type' => 'checkbox',
          'label' => __( 'Enable beGateway', 'woocommerce-begateway' ),
          'default' => 'yes'
        ),
        'title' => array(
          'title' => __( 'Title', 'woocommerce-begateway' ),
          'type' => 'text',
          'description' => __( 'This is the title displayed to the user during checkout.', 'woocommerce-begateway' ),
          'default' => __( 'Credit or debit card', 'woocommerce-begateway' )
        ),
        'admin_title' => array(
          'title' => __( 'Admin Title', 'woocommerce-begateway' ),
          'type' => 'text',
          'description' => __( 'This is the title displayed to the admin user', 'woocommerce-begateway' ),
          'default' => __( 'beGateway', 'woocommerce-begateway' )
        ),
        'description' => array(
          'title' => __( 'Description', 'woocommerce-begateway' ),
          'type' => 'textarea',
          'description' => __( 'This is the description which the user sees during checkout.', 'woocommerce-begateway' ),
          'default' => __("VISA, Mastercard", 'woocommerce-begateway')
        ),
        'shop-id' => array(
          'title' => __( 'Shop ID', 'woocommerce-begateway' ),
          'type' => 'text',
          'description' => __( 'Please enter your Shop Id.', 'woocommerce-begateway' ),
          'default' => ''
        ),
        'secret-key' => array(
          'title' => __( 'Secret Key', 'woocommerce-begateway' ),
          'type' => 'text',
          'description' => __( 'Please enter your Shop secret key.', 'woocommerce-begateway' ),
          'default' => ''
        ),
        'domain-gateway' => array(
          'title' => __( 'Payment gateway domain', 'woocommerce-begateway' ),
          'type' => 'text',
          'description' => __( 'Please enter payment gateway domain of your payment processor.', 'woocommerce-begateway' ),
          'default' => ''
        ),
        'domain-checkout' => array(
          'title' => __( 'Payment page domain', 'woocommerce-begateway' ),
          'type' => 'text',
          'description' => __( 'Please enter payment page domain of your payment processor.', 'woocommerce-begateway' ),
          'default' => ''
        ),
        'tx-type'      => array(
          'title' => __('Transaction Type', 'woocommerce-begateway'),
          'type' => 'select',
          'options' => $tx_options,
          'description' => __( 'Select Payment (Authorization & Capture)  or Authorization.', 'woocommerce-begateway' )
        ),

        'show-transaction-table' => array(
          'title' => __('Enable admin capture etc.', 'woocommerce-begateway'),
          'type' => 'checkbox',
          'label' => __('Show Transaction Table', 'woocommerce-begateway'),
          'description' => __( 'Allows admin to send capture/void/refunds', 'woocommerce-begateway' ),
          'default' => 'yes'
        ),

        'debug' => array(
          'title' => __( 'Debug Log', 'woocommerce-begateway' ),
          'type' => 'checkbox',
          'label' => __( 'Enable logging', 'woocommerce-begateway' ),
          'default' => 'no',
          'description' =>  __( 'Log events', 'woocommerce-begateway' ),
        )

      );
    } // end init_form_fields()

    function generate_begateway_form( $order_id ) {
      //creates a self-submitting form to pass the user through to the beGateway server
      global $woocommerce;
      $order = new WC_order( $order_id );
      if ( 'yes' == $this->debug ){
        $this->log->add( 'begateway', 'Generating payment form for order ' . $order->get_order_number()  );
      }
      // Order number & Cart Contents for description field - may change
      $item_loop = 0;
      //grab the langauge

      $lang = explode('-', get_bloginfo('language'));
      $lang = $lang[0];

      if(in_array($lang,\BeGateway\Language::getSupportedLanguages())) {
        $language=$lang;
      } else {
        $language='en';
      }

      $token = new \BeGateway\GetPaymentToken;

      if ($this->transaction_type == 'authorization') {
        $token->setAuthorizationTransactionType();
      }

      $token->money->setCurrency(get_woocommerce_currency());
      $token->money->setAmount($order->order_total);
      $token->setDescription(__('Order', 'woocommerce') . ' ' . $order->get_order_number());
      $token->setTrackingId(ltrim( $order->get_order_number(), '#' ));
      $token->customer->setFirstName($order->billing_first_name);
      $token->customer->setLastName($order->billing_last_name);
      $token->customer->setCountry($order->billing_country);
      $token->customer->setCity($order->billing_city);
      $token->customer->setPhone($order->billing_phone);
      $token->customer->setZip($order->billing_postcode);
      $token->customer->setAddress($order->billing_address_1. $order->billing_address_2);
      $token->customer->setEmail($order->billing_email);

      if (in_array($order->billing_country, array('US','CA'))) {
        $token->customer->setState($order->billing_state);
      }

      $cancel_url = $order->get_cancel_order_url();
      $cancel_url = str_replace('&amp;', '&', $cancel_url);

      $token->setSuccessUrl($this->get_return_url($order));
      $token->setDeclineUrl($cancel_url);
      $token->setFailUrl($cancel_url);
      $token->setCancelUrl($cancel_url);
      $token->setNotificationUrl($this->notify_url);

      $token->setLanguage($language);

      if ( 'yes' == $this->debug ){
        $this->log->add( 'begateway', 'Requesting token for order ' . $order->get_order_number()  );
      }

      $response = $token->submit();

      if(!$response->isSuccess()) {

        if ( 'yes' == $this->debug ){
          $this->log->add( 'begateway', 'Unable to get payment token on order: ' . $order_id . 'Reason: ' . $response->getMessage()  );
        }

        $woocommerce->add_error(__('Unable to contact the payment server at this time. Please try later.'));
        $woocommerce->add_error($response->getMessage());
        exit();
      }

      //now look to the result array for the token
      if ($response->getToken()) {
        $payment_url=$response->getRedirectUrlScriptName();

        update_post_meta(  ltrim( $order->get_order_number(), '#' ), '_Token', $token );
        if ( 'yes' == $this->debug ){
          $this->log->add( 'begateway', 'Token received, forwarding customer to: '.$payment_url);
        }
      } else{
        $woocommerce->add_error(__('Payment error: ').$response->getMessage());
        if ( 'yes' == $this->debug ){
          $this->log->add( 'begateway', 'Payment error order ' . $order_id.'  '.$error_to_show  );
        }
        exit('Sorry - there was an error contacting the bank server, please try later');
      }

      wc_enqueue_js('
        jQuery("body").block({
          message: "'.__('Thank you for your order. We are now redirecting you to make the payment.', 'woocommerce-begateway').'",
            overlayCSS: {
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
        jQuery("#submit_begateway_payment_form").click();
      ');
      return '<form action="'.$payment_url.'" method="post" id="begateway_payment_form">
        <input type="hidden" name="token" value="' . $response->getToken() . '">
        <input type="submit" class="button-alt" id="submit_begateway_payment_form" value="'.__('Make payment', 'woocommerce-begateway').'" /> <a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order &amp; restore cart', 'woothemes').'</a>
        </form>';
    }

    function process_payment( $order_id ) {
      global $woocommerce;

      $order = new WC_Order( $order_id );

      // Return payment page
      return array(
        'result'    => 'success',
        'redirect'	=> add_query_arg('key', $order->order_key, $order->get_checkout_payment_url( true ))
      );
    }
    // end process_payment

    function receipt_page( $order ) {

      echo $this->generate_begateway_form( $order );

    }

    function thankyou_page()
    {
      if ($this->description) echo wpautop(wptexturize($this->description));
    } // end thankyou_page


    public function create_order_transactions_meta_box()
    {
      //add a metabox
      add_meta_box( 'bt-begateway-order-transaction-content',
        $this->title,
        array(&$this, 'order_transaction_content_meta_box'),
        'shop_order', 'normal', 'default');
    }// end meta_box_order_transactions

    public function order_transaction_content_meta_box($post) {
      //wordpress strips <form> tags so you cannot send POST data instead you have to
      //make up a GET url and append the amount field using jQuery

      $order = new WC_Order($post->ID);
      $display="";

      //WordPress will also mutilate or just plain kill any PHP Sessions setup so, instead of passing the return URL that way we'll pop it into a post meta
      update_post_meta($post->ID,'_return_url', $this->curPageURL());

      switch ( $order->status){
      case 'pending':
        $display.=__('Order currently pending no capture/refund available', 'woocommerce-begateway');
        break;
      case 'on-hold':
        //params for capture
        $arr_params = array( 'wc-api' => 'BT_beGateway',
          'begateway' => 'capture',
          'uid' => md5(get_post_meta($post->ID, '_uid', true)),
          'oid' => $post->ID );
        $display.= $this->_getActionButton('capture', $order, $arr_params);
        //params for void
        $arr_params = array( 'wc-api' => 'BT_beGateway',
          'begateway' => 'void',
          'uid' => md5(get_post_meta($post->ID, '_uid', true)),
          'oid' => $post->ID );
        $display.= $this->_getActionButton('void', $order, $arr_params);

        break;
      case 'processing':
        //params for refund
        $arr_params = array( 'wc-api' => 'BT_beGateway',
          'begateway' => 'refund',
          'uid' => md5(get_post_meta($post->ID, '_uid', true)),
          'oid' => $post->ID );
        $display.= $this->_getActionButton('refund', $order, $arr_params);
        break;

      default:
        $display.='';
        break;
      }
      echo '<div class="panel-wrap woocommerce">';
      echo $display;
      echo '</div>';

    }// end order_transaction_content_meta_box


    private function plugin_url()
    {
      return $this->plugin;
    }// end plugin_url

    private function _getActionButton($action, $order, $arr_params) {
      switch($action) {
        case 'capture':
          $message = __('Please enter amount to capture','woocommerce-begateway');
          $btn_txt = __('Capture','woocommerce-begateway');
          break;
        case 'void':
          $message = __('Please enter amount to void','woocommerce-begateway');
          $btn_txt = __('Void','woocommerce-begateway');
          break;
        case 'refund':
          $message = __('Please enter amount to refund','woocommerce-begateway');
          $btn_txt = __('Refund','woocommerce-begateway');
          $refund_reason_txt = __('Refund reason','woocommerce-begateway');
          break;
        default:
          return '';
      }
      $display="<script>
        function add${action}URL(element)
        {
          jQuery(element).attr('href', function() {
            return this.href + '&amount='+ jQuery('#bt_${action}_amount').val();
          });
        }
        </script>";

      $display.='<p class="form-field"><label for="bt_' . $action . '_amount">'.$message.'</label>';
      $display.='<input type="text" id="bt_' . $action . '_amount" size="8" value="'. ($order->get_total() ).'" /></p>';
      if ($action == 'refund') {
        $display.='<p class="form-field"><label for="refund_comment">'.$refund_reason_txt.'</label>';
        $display.='<input type="text" size="30" value="" name="comment" id="refund_comment"></p>';
      }
      $display.='<a  onclick="javascript:add' . $action . 'URL(this);"  href="'.str_replace( 'https:', 'http:', add_query_arg( $arr_params, home_url( '/' ) ) ).'">';
      $display.='<button type="button" class="button">'.$btn_txt.'</button></a>';
      return $display;

    }

    /**
     *this function is called via the wp-api when the begateway server sends
     *callback data
    */
    function check_ipn_response() {
      //check for refund/capture/void
      if (isset($_GET['begateway']) && isset($_GET['uid']) &&   isset($_GET['oid'])){
        $this->child_transaction($_GET['begateway'], $_GET['uid'], $_GET['oid'], $_GET['amount'],$_GET['comment']);
        exit();
      }
      //do normal callback response

      global $woocommerce;

      $webhook = new \BeGateway\Webhook;

      if ($webhook->isAuthorized()) {

        //log
        if ( "yes" == $this->debug ){
          $display="\n-------------------------------------------\n";
          $display.= "Order No: ".$webhook->getTrackingId();
          $display.= "\nUID: ".$webhook->getUid();
          $display.="\n--------------------------------------------\n";
          $this->log->add( "begateway", $display  );
        }

        $this->process_order($webhook);

      } else {
        if ( "yes" == $this->debug ){
          $display="\n----------- Unable to proceed --------------\n";
          $display.= "Order No: ".$webhook->getTrackingId();
          $display.="\n--------------------------------------------\n";
          $this->log->add( "begateway", $display  );
        }
        wp_die( "beGateway Notify Failure" );
      }
    }
    //end of check_ipn_response

    function process_order($webhook) {
      global $woocommerce;

      $order_id = $webhook->getTrackingId();
      $order = new WC_Order( $order_id );
      $type = $webhook->getResponse()->transaction->type;

      if (in_array($type, array('payment','authorization'))) {
        $status = $webhook->getStatus();
        update_post_meta(  $order_id, '_uid', $webhook->getUid() );

        $messages = array(
          'payment' => array(
            'success' => __('Payment success.', 'woocommerce-begateway'),
            'failed' => __('Payment failed.', 'woocommerce-begateway'),
            'incomplete' => __('Payment incomplete, order status not updated.', 'woocommerce-begateway'),
            'error' => __('Payment error, order status not updated.', 'woocommerce-begateway'),
          ),
          'authorization' => array(
            'success' => __('Payment authorised. No money captured yet.', 'woocommerce-begateway'),
            'failed' => __('Authorization failed.', 'woocommerce-begateway'),
            'incomplete' => __('Authorisation incomplete, order status not updated.', 'woocommerce-begateway'),
            'error' => __('Authorisation error, order status not updated', 'woocommerce-begateway'),
          )

        );
        $messages['callback_error'] = __('Callback error, order status not updated', 'woocommerce-begateway');

        if ( 'yes' == $this->debug ){
          $this->log->add( 'begateway', 'Transaction type: ' . $type . '. Payment status '.$status.'. UID: '.$webhook->getUid());
        }

        if ($webhook->isSuccess()) {
          if ($type == 'payment' && $order->get_status() != 'processing') {
            $order->add_order_note( $messages[$type]['success'] . ' UID: ' . $webhook->getUid() . '<br>' );
            $order->payment_complete();
          } elseif ($order->get_status() != 'on-hold') {
            $order->update_status( 'on-hold', $messages[$type]['success'] . ' UID: ' . $webhook->getUid() . '<br>' );
          }
        } elseif ($webhook->isFailed()) {
            $order->update_status( 'failed', $messages[$type]['failed'] . ' UID: '. $webhook->getUid() .'<br>' );
        } elseif ($webhook->isIncomplete()) {
            $order->add_order_note( $messages[$type]['incomplete'] . ' UID: ' . $webhook->getUid() . '<br>' );
        } elseif ($webhook->isError()) {
            $order->add_order_note( $messages[$type]['error'] . ' UID: ' . $webhook->getUid() . '<br>' );
        } else {
            $order->add_order_note( $messages['callback_error'] . ' UID: ' . $webhook->getUid() . '<br>' );
        }
      }
    }//end function


    function child_transaction($type, $uid, $order_id, $amount, $reason = ''){
      global $woocommerce;
      $order = new WC_order( $order_id );
      //get the uid from order and compare to md5 in the $_GET
      $post_uid = get_post_meta($order_id,'_uid',true);
      $check_uid=md5($post_uid);
      if ($check_uid != $uid) {
        exit(__('UID is not correct','woocommerce-begateway'));
      }

      $messages = array(
        'void' => array(
          'not_possible' => __('Wrong order status. Void is not possible.', 'woocommerce-begateway'),
          'status' => __('Void status', 'woocommerce-begateway'),
          'failed' => __('Void attempt failed', 'woocommerce-begateway'),
          'success' => __('Payment voided', 'woocommerce-begateway'),
        ),
        'capture' => array(
          'not_possible' => __('Wrong order status. Capture is not possible.', 'woocommerce-begateway'),
          'status' => __('Capture status', 'woocommerce-begateway'),
          'failed' => __('Capture attempt failed', 'woocommerce-begateway'),
          'success' => __('Payment captured', 'woocommerce-begateway'),
        ),
        'refund' => array(
          'not_possible' => __('Wrong order status. Refund is not possible.', 'woocommerce-begateway'),
          'status' => __('Refund status', 'woocommerce-begateway'),
          'failed' => __('Refund attempt failed', 'woocommerce-begateway'),
          'success' => __('Payment refunded', 'woocommerce-begateway'),
        )
      );
      //check order status is on hold exit if not
      if (in_array($type,array('capture','void')) && $order->status !='on-hold') {
        exit($messages[$type]['not_possible']);
      }
      if (in_array($type,array('refund')) && $order->status !='processing') {
        exit($messages[$type]['not_possible']);
      }
      // now send data to the server

      $klass = '\\BeGateway\\' . ucfirst($type) . 'Operation';
      $transaction = new $klass();
      $transaction->setParentUid($post_uid);
      $transaction->money->setCurrency(get_woocommerce_currency());
      $transaction->money->setAmount($amount);

      if ($type == 'refund') {
        if (isset($reason) && !empty($reason)) {
          $transaction->setReason($reason);
        } else {
          $transaction->setReason(__('Refunded from Woocommerce', 'woocommerce-begateway'));
        }
      }

      $response = $transaction->submit();

      //determine status if success
      if($response->isSuccess()){

        if ($type == 'capture') {
          $order->payment_complete();
          $order->add_order_note( $messages[$type]['success'] . '. UID: ' . $response->getUid() );
          update_post_meta($order_id,'_uid',$response->getUid());
        } elseif ($type == 'void') {
          $order->update_status( 'cancelled', $messages[$type]['success'] . '. UID: ' . $response->getUid() );
        } elseif ($type == 'refund' ) {
          $order->update_status( 'refunded', $messages[$type]['success'] . '. UID: ' . $response->getUid() );
        }
        if ( 'yes' == $this->debug ){
          $this->log->add( 'begateway', $messages[$type]['status'].': '.$response->getMessage());
        }
        update_post_meta($order_id,'_bt_admin_message',$messages[$type]['success']);
      }else{
        if ( 'yes' == $this->debug ){
          $this->log->add( 'begateway', $messages[$type]['failed']. ': ' .$response->getMessage());
        }
        update_post_meta($order_id,'_bt_admin_error',$messages[$type]['failed']. ': ' .$response->getMessage());
      }
      $location = get_post_meta($order_id, '_return_url', true);
      delete_post_meta($order_id, '_return_url', true);

      header ('Location:'.  $location);
      exit();
    }

    function bt_admin_error(){
      if(isset($_GET['post']))
      {
        if(get_post_meta($_GET['post'],'_bt_admin_error'))
        {
          $error=get_post_meta($_GET['post'],'_bt_admin_error',true);
          delete_post_meta($_GET['post'],'_bt_admin_error');
          echo '<div class="error">
            <p>'.$error.'</p>
            </div>';
        }
      }
    }

    function bt_admin_message(){
      if(isset($_GET['post']))
      {
        if(get_post_meta($_GET['post'],'_bt_admin_message'))
        {
          $message=get_post_meta($_GET['post'],'_bt_admin_message',true);
          delete_post_meta($_GET['post'],'_bt_admin_message');
          echo '<div class="updated">
            <p>'.$message.'</p>
            </div>';
        }
      }
    }

    function bt_admin_hide(){
      //  remove_meta_box('postcustom', 'page', 'normal');
    }

    // Now we set that function up to execute when the admin_notices action is called

    function curPageURL() {
      $pageURL = 'http';
      if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on") {$pageURL .= "s";}
      $pageURL .= "://";
      if ($_SERVER["SERVER_PORT"] != "80") {
        $pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
      } else {
        $pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
      }
      $pageURL=rtrim($pageURL,'&message=1');
      return $pageURL;
    }
  }//end of class

  if(is_admin())
    new BT_beGateway();
}

//add to gateways
function bt_begateway_add_gateway( $methods )
{
    $methods[] = 'BT_beGateway';
    return $methods;
}
?>
