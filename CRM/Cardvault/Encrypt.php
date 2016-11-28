<?php

/**
 * @file
 * Utility class definition.
 *
 */

/**
 * Handles encryption of credit-card information.
 *
 */
class CRM_Cardvault_Encrypt {
  protected $_key = NULL;

  protected $errors = array();

  /**
   * Loads the key from the Vault key file.
   */
  function __construct() {
    if (!function_exists('mcrypt_module_open')) {
      CRM_Core_Error::fatal('Cardvault: php-mcrypt is missing.');
    }

    if (!defined('CIVICRM_SITE_KEY')) {
      CRM_Core_Error::fatal('Cardvault: CIVICRM_SITE_KEY is empty/undefined.');
    }

    $this->_key = CIVICRM_SITE_KEY;
  }

  /**
   * Decrypts cyphertext.
   *
   * @param $source
   *   Cyphertext. Text string containing encrypted $source.
   *
   * @return
   *   Plaintext. Text string to be encrypted.
   */
  public function decrypt($source) {
    if (empty($source)) {
      return;
    }

    $plaintext = CRM_Utils_Crypt::decrypt($source);
    $plaintext = unserialize(base64_decode($plaintext));

    return $plaintext;
  }

  /**
   * Encrypts plaintext.
   *
   * @param $source
   *   Array of card information to encrypt.
   *
   * @return
   *   Cyphertext. Text string containing encrypted $source.
   */
  public function encrypt($ccinfo) {
    if (empty($ccinfo)) {
      return;
    }

    // Note that the encrypt function only works on ASCII
    $source = base64_encode(serialize($ccinfo));

    return CRM_Utils_Crypt::encrypt($source);
  }

  /**
   * Accessor for errors property.
   */
  public function getErrors() {
    return $this->errors;
  }

  /**
   * Provides a hash that can be used to compare whether we have
   * already saved a CC in the vault.
   */
  public function hashCardData($ccinfo) {
    $hash = hash('sha256', $this->_key . $ccinfo['number'] . $ccinfo['cvv2']. $ccinfo['year'] . $ccinfo['month']);

    return $hash;
  }

}
