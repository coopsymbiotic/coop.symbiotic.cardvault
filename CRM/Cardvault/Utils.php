<?php

class CRM_Cardvault_Utils {
  /**
   * Returns TRUE if the card hash already exists for a given contact.
   */
  public static function card_hash_exists($hash, $contact_id, $contribution_id = NULL) {
    if ($contribution_id) {
      $dao = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_cardvault WHERE contact_id = %1 AND contribution_id = %2 AND hash = %3', [
        1 => [$contact_id, 'Positive'],
        2 => [$contribution_id, 'Positive'],
        3 => [$hash, 'String'],
      ]);

      if ($dao->fetch()) {
        return TRUE;
      }
    }
    else {
      $dao = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_cardvault WHERE contact_id = %1 AND hash = %2', [
        1 => [$contact_id, 'Positive'],
        2 => [$hash, 'String'],
      ]);

      if ($dao->fetch()) {
        return TRUE;
      }
    }

    return FALSE;
  }
}
