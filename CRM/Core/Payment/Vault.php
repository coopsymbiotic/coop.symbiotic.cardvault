<?php

class CRM_Core_Payment_Vault extends CRM_Core_Payment {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('Card Vault');
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   *
   */
  static function &singleton($mode, &$paymentProcessor) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new CRM_Core_Payment_Vault($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  function doDirectPayment(&$params) {
    CRM_Core_Error::fatal('This method should not be called directly.');
  }

  /**
   * Check whether a method is present ( & supported ) by the payment processor object.
   *
   * @param string $method
   *   Method to check for.
   *
   * @return bool
   */
  public function isSupported($method) {
    if ($method == 'updateSubscriptionBillingInfo') {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Implements the civi core method for updating payment credentials.
   */
  public function updateSubscriptionBillingInfo(&$message, $rawParams) {
    $ccinfo = array(
      'cardholder' => $rawParams['billing_first_name'] . ' ' . $rawParams['billing_last_name'],
      'type' => $rawParams['credit_card_type'],
      'number' => $rawParams['credit_card_number'],
      'cvv2' => $rawParams['cvv2'],
      'month' => (isset($rawParams['credit_card_exp_date']['M']) ? $rawParams['credit_card_exp_date']['M'] : $rawParams['credit_card_exp_date']['m']),
      'year' => $rawParams['credit_card_exp_date']['Y'],
    );

    // Note that the encrypt function only works on ASCII
    // Ubercart uses base64_encode() when it needs to encrypt other stuff.
    $crypt = new CRM_Cardvault_Encrypt();
    $cc = $crypt->encrypt($ccinfo);

    $errors = $crypt->getErrors();

    if (count($errors)) {
      watchdog('cardvault', 'encryption errors = ' . print_r($errors, 1));
    }

    $hash = hash('sha256', $key . $ccinfo['number'] . $ccinfo['cvv2']. $ccinfo['year'] . $ccinfo['month']);

    // ************************************** * * * * * *
    // FIXME FIXME : if non-admin, make sure that the contact can edit this subscription ID.
    // (otherwise someone could update another person's CC info.. potential rick-rolling?)
    // ************************************** * * * * * *

    CRM_Core_DAO::executeQuery('UPDATE civicrm_cardvault
      SET ccinfo = %1, hash = %2
      WHERE id = %3', [
      1 => [$cc, 'String'],
      2 => [$hash, 'String'],
      3 => [$rawParams['subscriptionId'], 'Positive'],
    ]);

    return TRUE;
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   the error message if any
   */
  public function checkConfig() {
     // FIXME : check for the key?
    return NULL;
  }

  function isError(&$response) {
    $responseCode = $response->getResponseCode();
    if (is_null($responseCode)) {
      return TRUE;
    }
    if ('null' == $responseCode) {
      return TRUE;
    }
    if (($responseCode >= 0) && ($responseCode < 50)) {
      return FALSE;
    }
    return TRUE;
  }

  // Is this necessary? copy-pasted from Moneris.
  function &checkResult(&$response) {
    return $response;

    $errors = $response->getErrors();
    if (empty($errors)) {
      return $result;
    }

    $e = CRM_Core_Error::singleton();
    if (is_a($errors, 'ErrorType')) {
      $e->push($errors->getErrorCode(),
        0, NULL,
        $errors->getShortMessage() . ' ' . $errors->getLongMessage()
      );
    }
    else {
      foreach ($errors as $error) {
        $e->push($error->getErrorCode(),
          0, NULL,
          $error->getShortMessage() . ' ' . $error->getLongMessage()
        );
      }
    }
    return $e;
  }

  function &error($error = NULL) {
    $e = CRM_Core_Error::singleton();
    if (is_object($error)) {
      $e->push($error->getResponseCode(),
        0, NULL,
        $error->getMessage()
      );
    }
    elseif (is_string($error)) {
      $e->push(9002,
        0, NULL,
        $error
      );
    }
    else {
      $e->push(9001, 0, NULL, "Unknown System Error.");
    }
    return $e;
  }

}
