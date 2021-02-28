<?php
if ( !defined( "ABSPATH" ) ) {
  exit;
}

if ( !class_exists( "WP_Async_Request" ) ) {
  require_once( NWSI_DIR_PATH . "includes/libs/wp-background-processing/wp-async-request.php" );
}

if ( !class_exists( "NWSI_Salesforce_Worker" ) ) {
  class NWSI_Salesforce_Worker extends WP_Async_Request {

    private $db;
    private $sf;

    protected $prefix = "nwsi";
    protected $action = "process_wc_order";

    /**
    * Class constructor
    */
    public function __construct() {
      parent::__construct();

      require_once( NWSI_DIR_PATH . "includes/models/class-nwsi-order-model.php" );
      require_once( NWSI_DIR_PATH . "includes/models/class-nwsi-order-item-model.php" );
      require_once( NWSI_DIR_PATH . "includes/models/class-nwsi-product-model.php" );
      require_once( NWSI_DIR_PATH . "includes/controllers/core/class-nwsi-db.php" );

      $this->db = new NWSI_DB();
      $this->sf = new NWSI_Salesforce_Object_Manager();
    }

    /**
    * Init async order processing procedure
    * @param int $order_id
    */
    public function process_order( $order_id ) {
      $this->data( array( "order_id" => $order_id ) );
      $this->dispatch();
    }
    /**
    * Extract order id from $_POST, process order and send data to Salesforce
    * @override
    */
    // protected function handle() {
    public function handle() {
      if ( array_key_exists( "order_id", $_POST ) && !empty( $_POST["order_id"] ) ) {
        $order_id = $_POST["order_id"];
      } else {
        return;
      }
      $this->handle_order( $order_id );
    }

    /**
     * Process order and send data to Salesforce.
     *
     * @param int $order_id
     */
    public function handle_order( int $order_id ) {
      $is_success = true;
      $error_message = array();
      $relationships = $this->db->get_active_relationships();

      if ( empty( $relationships ) ) {
        update_post_meta( $order_id, "_sf_sync_status", "failed" );
        array_push( $error_message, "NWSI: No defined relationships." );
        update_post_meta( $order_id, "_sf_sync_error_message", json_encode( $error_message ) );
        return;
      }

      $relationships = $this->prioritize_relationships( $relationships );

      // contains ids of created objects
      $response_ids = array();

      $order    = new NWSI_Order_Model( $order_id );
      $products = $this->get_products_from_order( $order );

      foreach( $relationships as $relationship ) {
        // get relationship connections
        $connections = json_decode( $relationship->relationships );

        if ( strtolower($relationship->from_object) === "order" ) {
          // process order
          $values = $this->get_values( $connections, $order );
          $this->set_dependencies(
            $relationship->to_object, $values,
            json_decode( $relationship->required_sf_objects ),
            $response_ids, $relationship->from_object
          );

          if ( !empty( $values ) ) {
            $response = $this->send_to_salesforce(
              $relationship->to_object, $values,
              json_decode( $relationship->unique_sf_fields ), 
              $response_ids,
              null,
              intval($relationship->active) === 2               // is read only
            );

            if ( intval($relationship->active) === 1 && !$response["success"] ) {
              $is_success = false;
              array_push( $error_message, $response["error_message"] );
              break; // no need to continue as this write object failed
            }
          }

        } else if ( strtolower($relationship->from_object) === "product" ) {
          $i = 0;
          foreach( $products as $product ) {
            $values = $this->get_values( $connections, $product );
            $this->set_dependencies( $relationship->to_object, $values,
            json_decode( $relationship->required_sf_objects ), $response_ids, $relationship->from_object, $i );

            if ( !empty( $values ) ) {
              $response = $this->send_to_salesforce( $relationship->to_object, $values,
              json_decode( $relationship->unique_sf_fields ), $response_ids, $i, intval($relationship->active) === 2 );

              if ( intval($relationship->active) === 1 && !$response["success"] ) {
                $is_success = false;
                array_push( $error_message, $response["error_message"] );
                break; // no need to continue
              }
            }
            $i++;
          }
        } else if ( strtolower($relationship->from_object) === "order_item" ) {
          $i = 0;
          $order_items = $order->get_items();
          foreach( $order_items as $item ) {
            $this_item = new NWSI_Order_Item_Model($item);
            $values = $this->get_values( $connections, $this_item );
            $this->set_dependencies( $relationship->to_object, $values,
            json_decode( $relationship->required_sf_objects ), $response_ids, $relationship->from_object, $i );

            if ( !empty( $values ) ) {
              $response = $this->send_to_salesforce( $relationship->to_object, $values,
              json_decode( $relationship->unique_sf_fields ), $response_ids, $i, intval($relationship->active) === 2 );

              if ( intval($relationship->active) === 1 && !$response["success"] ) {
                $is_success = false;
                array_push( $error_message, $response["error_message"] );
                break; // no need to continue
              }
            }
            $i++;
          }
        }
      } // for each relationship

      // handle order sync response
      $this->handle_order_sync_response( $order_id, $is_success, $error_message );
    }

    /**
     * Extract and return order items from order object
     * @param NWSI_Order_Model  $order
     * @return array - array of NWSI_Product_Model
     */
    private function get_products_from_order( $order ) {
      $product_items = $order->get_items();

      // prepare order items/products
      $products = array();
      foreach( $product_items as $product_item ) {
        // process order product
        $product = new NWSI_Product_Model( $product_item["product_id"] );
        $product->set_order_product_meta_data( $product_item["item_meta"] );

        array_push( $products, $product );
      }

      return $products;
    }

    /**
     * Save sync status and error messages to order meta data
     * @param int     $order_id
     * @param boolean $is_successful
     * @param array   $error_message
     */
    private function handle_order_sync_response( $order_id, $is_successful, $error_message ) {
      if ( $is_successful ) {
        update_post_meta( $order_id, "_sf_sync_status", "success" );
      } else {
        update_post_meta( $order_id, "_sf_sync_status", "failed" );
        update_post_meta( $order_id, "_sf_sync_error_message", json_encode( $error_message ) );
      }
    }

    /**
    * Send values to given object via Salesforce API
    * @param string  $to_object
    * @param array   $values
    * @param array   $unique_sf_fields
    * @param array   $response_ids (reference)
    * @param int     $id_index - in case we've multiple sf objects of the same type
    * @return array - [success, error_message]
    */
    private function send_to_salesforce( $to_object, $values, $unique_sf_fields, &$response_ids, $id_index = null, $is_read_only = false ) {
      $response = array();

      if ($is_read_only) {
        $sf_response = $this->sf->get_existing_object( $to_object, $values, $unique_sf_fields );
      } else {
        $sf_response = $this->sf->create_object( $to_object, $values, $unique_sf_fields );
      }

      // obtain SF response ID if any
      if ( $sf_response["success"] ) {
        $response["success"] = true;
        if ( is_null( $id_index ) ) {
          if (!array_key_exists($to_object, $response_ids)) {
            // only add to the response list if NOT already there - this way, multiple objects
            // can be added or searched (if read only relationship) and only the first successful
            // save or find will be used. 
            $response_ids[ $to_object ] = $sf_response["id"];
            // echo $to_object . ": " . $response_ids[ $to_object ] . "\n";
          }
        } else {
          $response_ids[ $to_object ][ $id_index ] = $sf_response["id"];
          // echo $to_object . ", " . $id_index . ": " . $response_ids[ $to_object ][ $id_index ] . "\n";
        }
      } else {
        $response["success"] = false;
        $response["error_message"] = $sf_response["error_code"] . " (" . $to_object . "): " . $sf_response["error_message"];
      }

      return $response;
    }

    /**
     * Check and return true if date is in Y-m-d format
     * @param string $date
     * @return boolean
     */
    private function is_correct_date_format( $date ) {
      if ( preg_match( "/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $date ) ) {
        return true;
      } else {
        return false;
      }
    }

    /**
    * Return populated array with field names and values
    * @param array      $connections
    * @param NWSI_Model $item
    * @return array
    */
    private function get_values( $connections, $item ) {
      $values = array();
      foreach( $connections as $connection ) {

        if ( $connection->source == "woocommerce" ) {
          $value = $item->get( $connection->from );
          // validation
          if ( $connection->type == "boolean" && !is_bool( $value ) ) {
            if ($value === "Yes") {
              $value = true;
            } else if ($value === "No") {
              $value = false;
            } else {
              $value = null;
            }
          } else if ( in_array( $connection->type, array( "double", "currency", "number", "percent" ) )
            && !is_numeric( $value ) ) {
            $value = null;
          } else if ( $connection->type == "email" ) {
            // sanitise the email
            $value = filter_var($value, FILTER_SANITIZE_EMAIL);
            // then check it's a valid email
            if ( !filter_var( $value, FILTER_VALIDATE_EMAIL) ) {
              $value = null;
            }
          } else if ( $connection->type == "date" ) {
            if ( !$this->is_correct_date_format( $value ) ) {
              try {
                $value = explode( " ", $value )[0];
                if ( !$this->is_correct_date_format( $value ) ) {
                  $value = date( "Y-m-d" );
                }
              } catch( Exception $exc ) {
                // not user friendly fallback but it will solved any required dates
                $value = date( "Y-m-d" );
              }
            }
          } else if ( !is_string( $value ) ) {
            if ( is_numeric( $value ) ) {
                // numbers can be saved into string objects (e.g. IDs)
                $value = strval($value); 
            }
            else {
              $value = null;
            }
          }

        } else if ( $connection->source == "sf-picklist" || $connection->source == "custom" ) {
          if ( $connection->type == "date" && $connection->value == "current" ) {
            $value = date( "Y-m-d" );
          } elseif ($connection->type == "boolean" && $connection->source == "custom") {
            $temp_value = explode( "-", $connection->from )[1];
            if ( !is_null( $temp_value ) ) {
              $value = $temp_value;
            } else {
              $value = ($connection->value === 'true');
            }
          } else {
            $value = $connection->value;
          }

          /* MASSIVE HACK FOR PAYMENT METHOD */
          if ($connection->to === "Payment_Method__c") {
            $method = $item->get("payment_method");
            if ($method == "paypal") {
              $value = "Online - Paypal";
            } elseif ($method == "stripe") {
              $value = 'Online - Stripe';
            }
          }
        }

        if ( !empty( $value ) ) {
          if ( array_key_exists( $connection->to, $values ) ) {
            $values[ $connection->to ] .= ", " . $value;
          } else {
            $values[ $connection->to ] = $value;
          }
        }
      }
      return $values;
    }

    /**
    * Check dependecies and update values array if needed
    * @param string 	$to_object
    * @param array		$response_ids
    * @param array		$values (reference)
    * @param array    $required_sf_objects
    * @param string   $from_object
    * @param int      $id_index - in case we've multiple sf objects of the same type
    */
    private function set_dependencies( $to_object, &$values, $required_sf_objects, $response_ids, $from_object, $id_index = null ) {
      foreach( $required_sf_objects as $required_sf_object ) {
        if ( is_array( $response_ids[ $required_sf_object->name ] ) ) {
          if ( empty( $id_index ) ) {
            $values[ $required_sf_object->id ] = $response_ids[ $required_sf_object->name ][0];
          } else {
            $values[ $required_sf_object->id ] = $response_ids[ $required_sf_object->name ][ $id_index ];
          }
        } else {
          // need to check if dependant object actually exists: if not, dont add to the values array
          // (object may not exist in target db so no need to add reference)
          // (will be up to the user to parse error messages and ensure all dependancies are set to read-write (not read only))
          if (array_key_exists($required_sf_object->name, $response_ids)) {
            $values[ $required_sf_object->id ] = $response_ids[ $required_sf_object->name ];
          }
        }
      }
    }

    /**
    * Sort relationships by Salesforce object dependencies
    * @param array $relationships
    * @return array
    */
    private function prioritize_relationships( $relationships ) {
      $prioritized_relationships = $relationships;

      for ( $i = 0; $i < sizeof( $relationships ); $i++ ) {
        $required_objects = json_decode( $relationships[$i]->required_sf_objects );
        foreach( $required_objects as $required_object ) {
          for ( $j = 0; $j < sizeof( $prioritized_relationships ); $j++ ) {
            if ( $relationships[$i]->id === $prioritized_relationships[$j]->id ) {
              continue;
            }
            if ( $required_object->name == $prioritized_relationships[$j]->to_object ) {
              //$current_position_of_required_object = $this->get_relationship_index_in_array( $prioritized_relationships, $prioritized_relationships[$j]->id );
              //if ( $current_position_of_required_object != -1 ) { // required object exists in array
                $position_of_dependant_relationship_in_prioritized_array = $this->get_relationship_index_in_array( $prioritized_relationships, $relationships[$i]->id );
                if ( $j > $position_of_dependant_relationship_in_prioritized_array ) { // object that is required is located after in prioritized array
                  // extract required object
                  $temp = array_splice( $prioritized_relationships, $j, 1 );
                  array_splice( $prioritized_relationships, $position_of_dependant_relationship_in_prioritized_array, 0, $temp );
                }
              //}
            }
          }
        } // foreach
      }
      return $prioritized_relationships;
    }

    /**
     * Return position of object in relationships array with the same id field
     * value or -1 in case of no matching object
     * @param array   $relationships
     * @param string  $id
     * @return int
     */
    private function get_relationship_index_in_array( $relationships, $id ) {
      for( $i = 0; $i < sizeof( $relationships ); $i++ ) {
        if ( $relationships[$i]->id == $id ) {
          return $i;
        }
      }
      return -1;
    }


    // Payments section

    public function get_sf_payments_for_user( $user_id ) {
  
      $to_return = array();
      
      $sf_response = $this->sf->get_existing_object( "Contact", array("WP_User_ID2__c" => $user_id), array("WP_User_ID2__c") );
      
      if ( $sf_response["success"] ) {
        $sf_user_id = $sf_response["id"];

        $payments = $this->sf->get_payments_for_sf_user($sf_user_id);

        $to_return = $payments;
      }
  
      return $to_return;
    }
  }
}
