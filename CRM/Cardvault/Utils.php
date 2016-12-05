<?php

class CRM_Cardvault_Utils {
  /**
   * Returns TRUE if the card hash already exists for a given contact.
   */
  public static function card_hash_exists($hash, $contact_id, $invoice_id = NULL) {
    if (!empty($invoice_id)) {
      $dao = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_cardvault WHERE contact_id = %1 AND invoice_id = %2 AND hash = %3', [
        1 => [$contact_id, 'Positive'],
        2 => [$invoice_id, 'String'],
        3 => [$hash, 'String'],
      ]);
    }
    else {
      $dao = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_cardvault WHERE contact_id = %1 AND invoice_id IS NULL AND hash = %3', [
        1 => [$contact_id, 'Positive'],
        3 => [$hash, 'String'],
      ]);
    }

    return $dao->fetch();
  }
}

