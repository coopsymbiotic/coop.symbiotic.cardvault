<?php

class CRM_Cardvault_Tokens_General {
  /**
   * See @cardvault_civicrm_tokenValues().
   */
  static public function tokenValues(&$values, $cids, $job = null, $tokens = array(), $context = null) {
    foreach ($cids as $contact_id) {
      $dao = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_cardvault WHERE contact_id = %1 ORDER BY created_date DESC', [
        1 => [$contact_id, 'Positive'],
      ]);

      if ($dao->fetch()) {
        $crypt = new CRM_Cardvault_Encrypt();

        $cc = $crypt->decrypt($dao->ccinfo);

        $values[$contact_id]['cardvault.card_on_file'] = CRM_Cardvault_BAO_Cardvault::obfuscateCCnumber($cc['number']) . ' (expires: ' . $cc['month'] . '/' . $cc['year'] . ')';
      }
    }
  }
}
