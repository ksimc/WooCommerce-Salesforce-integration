<?php
if ( !defined( "ABSPATH" ) ) {
  exit;
}

if ( !class_exists( "NWSI_Cryptor" ) ) {
  class NWSI_Cryptor {

    private $key;

    /**
    * Class constructor, use NWSI_KEY defined in wp-config.php or hardcoded one
    */
    function __construct() {
      require_once( NWSI_DIR_PATH . "includes/libs/crypto/Crypto.php" );
      // require_once( NWSI_DIR_PATH . "includes/libs/crypto/Crypto.php" );
      // require_once( NWSI_DIR_PATH . "includes/libs/crypto/Key.php" );
      // require_once( NWSI_DIR_PATH . "includes/libs/crypto/Core.php" );
      // require_once( NWSI_DIR_PATH . "includes/libs/crypto/Encoding.php" );
      // require_once( NWSI_DIR_PATH . "includes/libs/crypto/Encoding.php" );
      require_once( NWSI_DIR_PATH . "includes/libs/crypto/Exception/CryptoException.php" );
      foreach (glob(NWSI_DIR_PATH . "includes/libs/crypto/Exception/*.php") as $filename)
      {
          include_once $filename;
      }
      
      foreach (glob(NWSI_DIR_PATH . "includes/libs/crypto/*.php") as $filename)
      {
          include_once $filename;
      }
      
      // changing the key will force user to reauthenticate (rewrite DB entries)
      $plain_key = ( defined( "NWSI_KEY" ) ) ? NWSI_KEY : "def00000ca80de39d259aa5722c5d2c2710e320df4ca65798cf6f362f552780b35e78219f8bb0761bf32aa8e33230aa0c599f27ed699e0249ff480d83087fcdcbf4fb099";
      //$this->key = unpack( "H*", mb_strimwidth( $plain_key, 0, 8 ) )[1];

      // use this code in debugger to generate a new random key
      // has to be consistant across app usage so hard coded a viable key above
      // best to set in wp-config.php and then read in
      //
      //$this->key = Defuse\Crypto\Key::createNewRandomKey();
      //$plain_key = $this->key->saveToAsciiSafeString();

      $this->key = Defuse\Crypto\Key::loadFromAsciiSafeString($plain_key);
    }

    /**
    * Return encrypted data or false in case of failure
    * @param string  $data
    * @param boolean $encode - set to true for base64 encoding
    * @return mixed - boolean or string
    */
    public function encrypt( $data, $encode = false ) {
      try {

        $encrypted_data =   Defuse\Crypto\Crypto::Encrypt( $data, $this->key );

        if ( $encode ) {
          return base64_encode( $encrypted_data );
        } else {
          return $encrypted_data;
        }

      } catch ( Exception $ex ) {
        return false;
      }
    }

    /**
    * Return decrypted data or false in case of failure
    * @param string  $data
    * @param boolean $encoded - set to true if $data is base64 encoded
    * @return mixed
    */
    public function decrypt( $data, $encoded = false ) {
      try {
        if ( $encoded ) {
          $data = base64_decode( $data );
        }
        return Defuse\Crypto\Crypto::Decrypt( $data, $this->key );

      } catch ( Exception $ex ) {
        return false;
      }
    }
  }
}
