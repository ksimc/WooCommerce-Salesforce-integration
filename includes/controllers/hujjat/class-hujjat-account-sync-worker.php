<?php
if ( !defined( "ABSPATH" ) ) {
  exit;
}

if ( !class_exists( "HUJJAT_Account_Sync_Worker" ) ) {
    class HUJJAT_Account_Sync_Worker {
      public function init() {
        // ------------------
        // 1. Register new endpoint to use for My Account page
        // Note: Resave Permalinks or it will give 404 error
        add_action( 'init', 'hujjat_add_members_area_endpoint' );


        // ------------------
        // 2. Add new query var
        add_filter( 'query_vars', 'hujjat_members_area_query_vars', 0 );


        // ------------------
        // 3. Insert the new endpoint into the My Account menu
        add_filter( 'woocommerce_account_menu_items', 'hujjat_add_members_area_link_my_account' );


        // ------------------
        // 4. Add content to the new endpoint
        add_action( 'woocommerce_account_hujjat-member-area_endpoint', 'hujjat_members_area_content' );
        // Note: add_action must follow 'woocommerce_account_{your-endpoint-slug}_endpoint' format

        // hook into new user registration to write user-id to Salesforce
        add_action('user_register','hujjat_register_new_user');
      }
    }

    function hujjat_register_new_user(){
      $instance_of_main = NWSI_Main::get_instance();
      $worker = $instance_of_main->worker;

      
    }

    function hujjat_add_members_area_endpoint() {
      add_rewrite_endpoint( 'hujjat-member-area', EP_ROOT | EP_PAGES );
    }

    function hujjat_members_area_query_vars( $vars ) {
      $vars[] = 'hujjat-member-area';
      return $vars;
    }

    function hujjat_add_members_area_link_my_account( $items ) {
      $items['hujjat-member-area'] = 'Members Area';
      return $items;
    }

    function hujjat_members_area_content() {
      echo '<h3>Hujjat members area</h3><br/><p>Welcome to the Hujjat members area. Over time we will be adding more functionality regarding the membership data we hold linked to this hujjat.org account.</p>';
      echo do_shortcode( ' /* your shortcode here */ ' );
    }
}