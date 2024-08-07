<?php

require_once 'cardvault.civix.php';

/** Name of encryption key file. */
define('CIVICRMRECURRING_CREDIT_KEYFILE_NAME', 'cardvault.key');

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function cardvault_civicrm_config(&$config) {
  _cardvault_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function cardvault_civicrm_managed(&$entities) {
  $entities[] = array(
    'module' => 'coop.symbiotic.cardvault',
    'name' => 'Vault',
    'entity' => 'PaymentProcessorType',
    'params' => array(
      'version' => 3,
      'name' => 'Vault',
      'title' => 'Credit Card Vault',
      'description' => 'Credit card vault (not an actual processor).',
      'class_name' => 'Payment_Vault',
      'billing_mode' => 'form',
      'user_name_label' => 'User Name',
      'password_label' => 'API Token',
      'url_site_default' => 'https://example.org/',
      'url_recur_default' => 'https://example.org/',
      'url_site_test_default' => 'https://example.org/',
      'url_recur_test_default' => 'https://example.org/',
      'is_recur' => 1,
      'payment_type' => 1,
    ),
  );
  return;
}

/**
 * Implements hook_civicrm_alterPaymentProcessorParams().
 * Saves CC details the database.
 */
function cardvault_civicrm_alterPaymentProcessorParams($paymentObj, &$rawParams, &$cookedParams) {
  // This is set when we are processing a card from Cardvault,
  // so we don't want to save it again.
  if (!empty($rawParams['is_from_cardvault'])) {
    return;
  }

  CRM_Cardvault_BAO_Cardvault::create([
    'contact_id' => $rawParams['contactID'],
    'contribution_id' => CRM_Utils_Array::value('contributionID', $rawParams),
    'invoice_id' => CRM_Utils_Array::value('invoiceID', $rawParams),
    'billing_first_name' => $rawParams['billing_first_name'],
    'billing_last_name' => $rawParams['billing_last_name'],
    'credit_card_type' => $rawParams['credit_card_type'],
    'credit_card_number' => $rawParams['credit_card_number'],
    'cvv2' => $rawParams['cvv2'],
    'credit_card_expire_month' => (isset($rawParams['credit_card_exp_date']['M']) ? $rawParams['credit_card_exp_date']['M'] : $rawParams['credit_card_exp_date']['m']),
    'credit_card_expire_year' => $rawParams['credit_card_exp_date']['Y'],
    'currency' => $rawParams['currencyID'],
  ]);
}

function cardvault_civicrm_summary($contact_id, &$content, &$contentPlacement = CRM_Utils_Hook::SUMMARY_BELOW) {
  // Shows a preview of the credit card data that we have saved.
  // This is more for debugging than anything else.
  // Users and admins can also check the Contact Dashboard.
  $dao = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_cardvault WHERE contact_id = %1', [
    1 => [$contact_id, 'Positive'],
  ]);

  $crypt = new CRM_Cardvault_Encrypt();

  while ($dao->fetch()) {
    // FIXME: do more checks to avoid PHP notices on bad data?
    $cc = $crypt->decrypt($dao->ccinfo);
    $cc_number = '************' . substr($cc['number'], -4, 4);
    $content .= '<p>' . $cc['cardholder'] . ', ' . $cc['type'] . ', ' . $cc_number . ', expires: ' . sprintf('%02d', $cc['month']) . '/' . $cc['year'] . '</p>';
  }
}

/**
 * Implements hook_civicrm_buildForm() is a completely overkill way.
 * Searches for an override class named after the initial $formName
 * and calls its buildForm().
 *
 * Ex: for a $formName "CRM_Case_Form_CaseView", it will:
 * - try to find * CRM/Cardvault/Case/Form/CaseView.php,
 * - require_once the file, instanciate an object, and
 * - call its buildForm() function.
 *
 * Why so overkill? My buildForm() implementations tend to become
 * really big and numerous, and even if I split up into multiple
 * functions, it still makes a really long php file.
 */
function cardvault_civicrm_buildForm($formName, &$form) {
  $formName = str_replace('CRM_', 'CRM_Cardvault_', $formName);
  $parts = explode('_', $formName);
  $filename = dirname(__FILE__) . '/' . implode('/', $parts) . '.php';

  if (file_exists($filename)) {
    require_once $filename;
    $foo = new $formName;

    if (method_exists($foo, 'buildForm')) {
      $foo->buildForm($form);
    }
  }
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function cardvault_civicrm_install() {
  _cardvault_civix_civicrm_install();
}

/**
* Implements hook_civicrm_postInstall().
*
* @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
*/



/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function cardvault_civicrm_enable() {
  _cardvault_civix_civicrm_enable();
}

/**
 * @param $params
 * @param $context
 *
 * When alterating a template for a contribution.  Add the masked cc_type and cc_number to
 * the template
 *
 */
function cardvault_civicrm_alterMailParams(&$params, $context) {
  if ("messageTemplate" == $context && !empty($params['tplParams']['contributionID'])) {
    $ccInfo = CRM_Cardvault_BAO_Cardvault::getCCInfo($params['tplParams']['contributionID']);
    if ($ccInfo) {
      $params['tplParams']['cc_type'] = $ccInfo['cc_type'];
      $params['tplParams']['cc_number'] = $ccInfo['cc_number'];
    }
  }
}

/**
 * Implements hook_civicrm_tokens().
 */
function cardvault_civicrm_tokens(&$tokens) {
  if (!isset($tokens['contact'])) {
    $tokens['contact'] = [];
  }

  if (!isset($tokens['cardvault'])) {
    $tokens['cardvault'] = [];
  }

  $tokens['contact']['cardvault.card_on_file'] = ts('Cardvault card on file');
}

/**
 * Implements hook_civicrm_tokenValues().
 */
function cardvault_civicrm_tokenValues(&$values, $cids, $job = null, $tokens = array(), $context = null) {
  CRM_Cardvault_Tokens_General::tokenValues($values, $cids, $job, $tokens, $context);
}

/**
 * Implements hook_civicrm_queryObjects().
 */
function cardvault_civicrm_queryObjects(&$queryObjects, $type = 'Contact') {
  if ($type == 'Contact') {
    $queryObjects[] = new CRM_Cardvault_BAO_Query();
  }
}

/**
 * Implements hook_civicrm_searchTasks().
 */
function cardvault_civicrm_searchTasks($objectType, &$tasks) {
  if ($objectType == 'membership') {
    $tasks[105] = array(
      'title' => ts('Cardvault: Renew membership pending contribution', array('domain' => 'coop.symbiotic.cardvault')),
      'class' => 'CRM_Cardvault_Form_Task_Renewpending',
      'result' => TRUE,
    );
  }
  if ($objectType == 'contribution') {
    $tasks[105] = array(
      'title' => ts('Cardvault: Process pending contribution', array('domain' => 'coop.symbiotic.cardvault')),
      'class' => 'CRM_Cardvault_Form_Task_ChargePending',
      'result' => TRUE,
    );
  }
}

/**
 * Implements hook_civicrm_check().
 */
function cardvault_civicrm_check(&$messages) {
  if (!function_exists('mcrypt_module_open')) {
    $messages[] = new CRM_Utils_Check_Message(
      'cardvault_php_mcrypt',
      ts('php%1-mcrypt is missing.', [1 => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION]),
      ts('Cardvault - PHP mcrypt'),
      \Psr\Log\LogLevel::CRITICAL,
      'fa-flag'
    );
    return;
  }

  $messages[] = new CRM_Utils_Check_Message(
    'cardvault_php_mcrypt',
    ts('Package is installed.'),
    ts('Cardvault - mcrypt'),
    \Psr\Log\LogLevel::INFO,
    'fa-check'
  );
}
