<?php

class CRM_Cardvault_Contribute_Form_ContributionView {

  /**
   * Implements civicrm_hook_buildForm()
   * for CRM_Contribute_Form_Contribution
   * aka backend form to add a new contribution.
   */
  public static function buildForm(&$form) {

    $contribution_id = $form->get('id');

    $contribution = civicrm_api3('Contribution', 'getsingle', [
      'id' => $contribution_id,
    ]);

    if ($contribution['contribution_status_id'] != 1) {
      CRM_Core_Region::instance('page-body')->add([
        'markup' => '<span class="crm-button crm-button-type-cardvault-charge crm-i-button" data-cardvault-contribution-id="' . $contribution_id . '"><i class="crm-i fa-credit-card"></i> <input class="crm-form-submit default" crm-icon="fa-credit-card" name="crm-cardvault-process" value="Charge card on file" id="crm-cardvault-process" type="submit"></span>',
      ]);

      CRM_Core_Resources::singleton()->addScriptFile('coop.symbiotic.cardvault', 'js/cardvault-process.js', 10, 'page-body');
    }
  }

}
