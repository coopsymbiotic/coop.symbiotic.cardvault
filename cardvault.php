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
  return _cardvault_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_alterPaymentProcessorParams().
 * Saves CC details the database.
 */
function cardvault_civicrm_alterPaymentProcessorParams($paymentObj, &$rawParams, &$cookedParams) {
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
 * Implements hook_civicrm_xmlMenu().
 *
 * @param array $files
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function cardvault_civicrm_xmlMenu(&$files) {
  _cardvault_civix_civicrm_xmlMenu($files);
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
function cardvault_civicrm_postInstall() {
  _cardvault_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function cardvault_civicrm_uninstall() {
  _cardvault_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function cardvault_civicrm_enable() {
  _cardvault_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function cardvault_civicrm_disable() {
  _cardvault_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed
 *   Based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function cardvault_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _cardvault_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * @param array $caseTypes
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function cardvault_civicrm_caseTypes(&$caseTypes) {
  _cardvault_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function cardvault_civicrm_angularModules(&$angularModules) {
_cardvault_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function cardvault_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _cardvault_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function cardvault_civicrm_navigationMenu(&$menu) {
  _cardvault_civix_insert_navigation_menu($menu, NULL, array(
    'label' => ts('The Page', array('domain' => 'coop.symbiotic.cardvault')),
    'name' => 'the_page',
    'url' => 'civicrm/the-page',
    'permission' => 'access CiviReport,access CiviContribute',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _cardvault_civix_navigationMenu($menu);
} // */
