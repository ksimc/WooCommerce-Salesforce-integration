<?php
if ( !defined( "ABSPATH" ) ) {
  exit;
}

if ( !class_exists( "WP_Async_Request" ) ) {
  require_once( NWSI_DIR_PATH . "includes/libs/wp-background-processing/wp-async-request.php" );
}

if ( !class_exists( "HUJJAT_Account_Async_Worker" ) ) {
    class HUJJAT_Account_Async_Worker extends WP_Async_Request {

      private $db;
      private $sf;

      /**
      * Class constructor
      */
      public function __construct() {
        parent::__construct();

        // require_once( NWSI_DIR_PATH . "includes/models/class-nwsi-order-model.php" );
        // require_once( NWSI_DIR_PATH . "includes/models/class-nwsi-order-item-model.php" );
        // require_once( NWSI_DIR_PATH . "includes/models/class-nwsi-product-model.php" );
        require_once( NWSI_DIR_PATH . "includes/controllers/core/class-nwsi-db.php" );

        $this->db = new NWSI_DB();
        $this->sf = new NWSI_Salesforce_Object_Manager();

        $this->setup_woocommerce_members_area_section();
        $this->listen_for_stripe_webhook_events();
      }
      
      private function setup_woocommerce_members_area_section() {
        // ------------------
        // 1. Register new endpoint to use for My Account page
        // Note: Resave Permalinks or it will give 404 error
        add_action( 'init', array ($this, 'hujjat_add_members_area_endpoint' ) );

        // ------------------
        // 2. Add new query var
        add_filter( 'query_vars', array ($this, 'hujjat_members_area_query_vars' ), 0 );

        // ------------------
        // 3. Insert the new endpoint into the My Account menu
        //add_filter( 'woocommerce_account_menu_items', array ($this, 'hujjat_add_members_area_link_my_account' ) );

        // ------------------
        // 4. Add content to the new endpoint
        //add_action( 'woocommerce_account_hujjat-member-area_endpoint', array ($this, 'hujjat_members_area_content' ) );
        // Note: add_action must follow 'woocommerce_account_{your-endpoint-slug}_endpoint' format

        // hook into new user registration to write user-id to Salesforce
        //add_action('user_register', array ($this, 'hujjat_register_new_user' ));
      }

      private function listen_for_stripe_webhook_events() {
        add_action('hujjat_stripe_callback_process_payload', array($this, 'hujjat_stripe_callback_payload_processor'));
      }

      public function handle() {
        $this->hujjat_stripe_callback_payload_processor_async( $_POST );
      }

      public function hujjat_stripe_callback_payload_processor($event_json) {
        $this->data( $event_json );
        $this->dispatch();
      }

      public function hujjat_stripe_callback_payload_processor_async($event_json) {
        // set your secret key: remember to change this to your live secret key in production
    
        // See your keys here: https://dashboard.stripe.com/account/apikeys
        \Stripe\Stripe::setApiKey('sk_test_51GtwfoJCo83719t3TwTP066FnZmpuSNcE3dDNGQ5K98F8AWedPRU4yJVPd1onOcvPmuWPLyPszFLSaPt7UlTydXL00R5yVY6b6');
    
        // do something with $event_json
        if(array_key_exists('id', $event_json)) {
          // this will be used to retrieve the event from Stripe
          $event_id = $event_json['id'];
          
          try {
            // to verify this is a real event, we re-retrieve the event from Stripe 
            $event = \Stripe\Event::retrieve($event_id);
            
            // successful payment
            if($event->type == 'checkout.session.completed') {

                
              $stripe_session = $event->data->object;

              // check if its a "quick donation"
              $payment_intent = \Stripe\PaymentIntent::retrieve($stripe_session->payment_intent);
              $description = $payment_intent->description;
              $description_key_found = strpos($description, "Online (quick) Donation");                       /*** MAGIC STRING ***/

              if ($description_key_found !== false && $description_key_found >= 0) {
                $charge_id = "";
                try {
                    $charge_id = $payment_intent->charges->data[0]->id;
                } catch (Exception $e) {
                }

                $amount = $stripe_session->amount_total / 100; // amount comes in as amount in cents, so we need to convert to dollars

                $payment_method = \Stripe\PaymentMethod::retrieve($payment_intent->payment_method);
                
                // retrieve the payer's information
                $customer = \Stripe\Customer::retrieve($stripe_session->customer);
                $address = array(
                    'email'      => $payment_method->billing_details->email,
                    'last_name'  => $payment_method->billing_details->name,
                    'address_1'  => $payment_method->billing_details->address->line1,
                    'address_2'  => $payment_method->billing_details->address->line2,
                    'city'       => $payment_method->billing_details->address->city,
                    'state'      => $payment_method->billing_details->address->state,
                    'postcode'   => $payment_method->billing_details->address->postal_code,
                    'country'    => $payment_method->billing_details->address->country
                );

                // create woo commerce order
                global $woocommerce;

                // first, check if we've processed this event ID already. Stripe may send twice. 
                // do this by checking if any posts have the event id as metadata already.
                $already_processed = $this->db->check_if_processed_stripe_event($event_id);
                if (!$already_processed) {
                  // Now we create the order
                  $order = wc_create_order();
                  $order->add_meta_data("stripe_session_checkout_completed_event_id", $event_id, true);

                  $product = null;
                  try {
                    $product_id = intval($payment_intent->metadata->wc_product_id);
                    $product = get_product($product_id);

                    // set price from stripe value
                    $product->set_price($amount);

                    // add to order (qnty = 1)
                    $order->add_product( $product, 1 );

                  } catch (Exception $e) {
                    // failed to get user id (not a logged in user, or not a number in the metadata string)
                    $break_for_debug = true;
                  }

                  $order->set_address( $address, 'billing' );

                  // set the stipe transaction ID
                  $order->set_date_paid( gmdate("Y-m-d H:i:s") );
                  $order->set_created_via("Homepage Quick Donate");
                  
                  $order->set_payment_method("stripe");
                  $order->set_payment_method_title("Credit Card (Stripe)");
                  
                  try {
                    //$order->set_customer_id = intval($payment_intent->metadata->wp_user_id);
                    update_post_meta($order->id, '_customer_user', intval($payment_intent->metadata->wp_user_id));
                    //$order->add_meta_data("_customer_user", intval($payment_intent->metadata->wp_user_id), true);
                  } catch (Exception $e) {
                      // failed to get user id (not a logged in user, or not a number in the metadata string)
                      $break_for_debug = true;
                  }

                  // $order->set_status("Completed");
                  $order->calculate_totals();
                  $order->payment_complete( $charge_id );
                  $order->save();
                  do_action( 'woocommerce_thankyou', $order->get_id() );
                }
              }
                  
                
            }
            // failed payment
            if($event->type == 'charge.failed') { }
          } catch (Exception $e) {
              // something failed, perhaps log a notice or email the site admin
          } 
        }
      }

      public function hujjat_register_new_user(){
        // $instance_of_main = NWSI_Main::get_instance();
        // $worker = $instance_of_main->worker;
      }
  
      public function hujjat_add_members_area_endpoint() {
        add_rewrite_endpoint( 'hujjat-member-area', EP_ROOT | EP_PAGES );
      }
  
      public function hujjat_members_area_query_vars( $vars ) {
        $vars[] = 'hujjat-member-area';
        return $vars;
      }
  
      public function hujjat_add_members_area_link_my_account( $items ) {
        $items['hujjat-member-area'] = 'Members Area';
        return $items;
      }
  
      public function hujjat_members_area_content() {
        echo '<h3>Hujjat members area</h3><br/><p>Welcome to the Hujjat members area. Over time we will be adding more functionality regarding the membership data we hold linked to this hujjat.org account.</p><p>This area is still <b>in development</b> - please disregard information on this page until this notice is removed.</p>';
        
        $instance_of_main = NWSI_Main::get_instance();
        $opportunities = $instance_of_main->get_sf_payments_for_user(get_current_user_id());

        // render payments
        echo '<p>We have ' . count($opportunities) . '  recorded payments for you in our database.';

        if (count($opportunities) > 0) {
          echo '<table>';

          echo '<tr> <td>Date</td> <td>Amount</td> <td>Gift Aid</td> <td>Method</td> <td>Payment Type</td> <td>Funds</td> </tr>';

          foreach ($opportunities as $opportunity) {
            foreach ($opportunity['npe01__OppPayment__r']['records'] as $payment) {
              $funds = "";
              if ($opportunity['npsp__Allocations__r']) {
                foreach($opportunity['npsp__Allocations__r']['records'] as $line_item) {
                  if (strlen($funds) > 0) {
                    $funds .= "; ";
                  }
                  
                  $funds .= $line_item["npsp__General_Accounting_Unit__r"]["Name"];

                  if ($opportunity['npsp__Allocations__r']['totalSize'] > 1) {
                    $funds .= '(' . $line_item["npsp__Percent__c"] . '%)';
                  }
                }
              }
              echo '<tr>'
              .'<td>'. $payment['npe01__Payment_Date__c'] .'</td>'
              .'<td>'. $payment['npe01__Payment_Amount__c'] .'</td>'
              .'<td>'. $opportunity['Gift_Aid__c'] .'</td>'
              .'<td>'. $payment['npe01__Payment_Method__c'] .'</td>'
              .'<td>'. $this->convert_opportunity_record_type($opportunity['RecordTypeId']) .'</td>'
              .'<td>'. $funds .'</td>'
              .'</tr>';
            }
          }

          echo '</table>';
        }
        
      }

      private function convert_opportunity_record_type($record_type) {
        if ($record_type === "0123z000000fGP8AAM") {
          return "Donation";
        } else {
          return "Membership";
        } 
      }

      /**
       * Plugin install action.
       * Flush rewrite rules to make our custom endpoint available.
       */
      public static function install() {
        flush_rewrite_rules();
      }

    }   
}