<?php

/**
 * @file
 * Utility class definition.
 *
 * ***** TAKEN FROM UBERCART 7.x-3.0-rc3 **********
 * ***** WITH A FEW MINOR RENAMES TO AVOID NAMESPACE CLASHES ****
 *
 * This is a toy encryption, a fancy XOR operation.
 * Using AES or something else based on mcrypt_encrypt might be better,
 * or better yet, something asymetric, but in this case, the actual
 * encryption is not going to be the weakest link. Given that the key
 * is stored on the server, it's pretty easy to break this.
 */

/**
 * Handles encryption of credit-card information.
 *
 * Trimmed down version of GPL class by Tony Marston.  Details available at
 * http://www.tonymarston.co.uk/php-mysql/encryption.html
 *
 * Usage:
 * 1) Create an encryption object.
 *    ex: $crypt = new CRM_Cardvault_Encrypt();
 * 2) To encrypt string data, use the encrypt method with the key.
 *    ex: $encrypted = $crypt->encrypt($array);
 * 3) To decrypt string data, use the decrypt method with the original key.
 *    ex: $decrypted = $crypt->decrypt($array);
 * 4) To check for errors, use the errors method to return an array of errors.
 *    ex: $errors = $crypt->getErrors();
 */
class CRM_Cardvault_Encrypt {
  protected $_key = NULL;

  protected static $scramble1 = '! #$%&()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[]^_`"abcdefghijklmnopqrstuvwxyz{|}~';
  protected static $scramble2 = 'f^jAE]okIOzU[2&q1{3`h5w_794p@6s8?BgP>dFV=m" D<TcS%Ze|r:lGK/uCy.Jx)HiQ!#$~(;Lt-R}Ma,NvW+Ynb*0X';

  protected $errors = array();
  protected $adj = 1.75;
  protected $mod = 3;

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
    $this->errors = array();

    if (empty($source)) {
      // Commented out to prevent errors getting logged for use cases that may
      // have variable encryption/decryption requirements. -RS
      // $this->errors[] = t('No value has been supplied for decryption');
      return;
    }

    return CRM_Utils_Crypt::decrypt($source);
  }

  /**
   * Encrypts plaintext.
   *
   * @param $source
   *   Array of card information to encrypt.
   * @param $sourcelen
   *   Minimum plaintext length.  Plaintext $source which is shorter than
   *   $sourcelen will be padded by appending spaces.
   *
   * @return
   *   Cyphertext. Text string containing encrypted $source.
   */
  public function encrypt($ccinfo, $sourcelen = 0) {
    $this->errors = array();

    if (empty($ccinfo)) {
      // Commented out to prevent errors getting logged for use cases that may
      // have variable encryption/decryption requirements. -RS
      // $this->errors[] = t('No value has been supplied for encryption');
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
