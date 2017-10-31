<?php

/**
 * Cardvault.fixdata API - Helper API to help fix data during upgrades.
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_cardvault_fixdata($params) {

  $crypt = new CRM_Cardvault_Encrypt();

  $dao = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_cardvault');

  while ($dao->fetch()) {
    $cc = $crypt->decrypt($dao->ccinfo);

    $expiry = $cc['year'] . sprintf('%02d', $cc['month']) . '01';
    $mask = '************' . substr($cc['number'], -4, 4);
    $cc_type = $cc['type'];

    if ($cc['month'] && $cc['year']) {
      CRM_Core_DAO::executeQuery('UPDATE civicrm_cardvault SET expiry_date = %1, masked_account_number = %2, cc_type = %3 WHERE id = %4', [
        1 => [$expiry, 'Timestamp'],
        2 => [$mask, 'String'],
        3 => [$cc_type, 'String'],
        4 => [$dao->id, 'Positive'],
      ]);
    }
  }
}
