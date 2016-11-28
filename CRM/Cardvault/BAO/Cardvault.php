<?php

class CRM_Cardvault_BAO_Cardvault {

  /**
   * Records a new card in the vault.
   *
   * See @cardvault_civicrm_alterPaymentProcessorParams for an example.
   */
  public static function create($params) {
    if (empty($params['contact_id'])) {
      CRM_Core_Error::fatal('Cardvault: Missing contact_id.');
    }

    if (empty($params['credit_card_number'])) {
      CRM_Core_Error::fatal('Cardvault: Missing credit_card_number.');
    }

    // TODO: validate other params?
    // Mostly called from cardvault_civicrm_alterPaymentProcessorParams(),
    // so params were validated upstream.
    // Also called from the migration scripts, they should validate too.

    $ccinfo = [
      'cardholder' => $params['billing_first_name'] . ' ' . $params['billing_last_name'],
      'type' => $params['credit_card_type'],
      'number' => $params['credit_card_number'],
      'cvv2' => $params['cvv2'],
      'month' => $params['credit_card_expire_month'],
      'year' => $params['credit_card_expire_year'],
    ];

    $crypt = new CRM_Cardvault_Encrypt();
    $cc = $crypt->encrypt($ccinfo);

    $errors = $crypt->getErrors();
    
    if (count($errors)) {
      watchdog('cardvault', 'encryption errors = ' . print_r($errors, 1));
    }

    $hash = $crypt->hashCardData($ccinfo);

    if (CRM_Cardvault_Utils::card_hash_exists($params['contact_id'], $hash)) {
      Civi::log()->info(ts('Card already in vault for Contact %1', [
        1 => $params['contact_id'],
      ]));
      return;
    }

    CRM_Core_DAO::executeQuery('INSERT INTO civicrm_cardvault(contribution_id, contact_id, timestamp, ccinfo, hash)
      VALUES (%1, %2, %3, %4, %5)', [
      1 => [$params['contribution_id'], 'Positive'],
      2 => [$params['contact_id'], 'Positive'],
      3 => [time(), 'Positive'],
      4 => [$cc, 'String'],
      5 => [$hash, 'String'],
    ]);

    // civicrm_contribution_recur.processor_id is the external ID for the payment processor
    // this is required for editing the payment info.
    $processor_id = CRM_Core_DAO::singleValueQuery('SELECT id FROM civicrm_cardvault WHERE contact_id = %1 AND hash = %2', [
      1 => [$params['contact_id'], 'Positive'],
      2 => [$hash, 'String'],
    ]);

    /**
     * We create a pseudo recurring contribution tied to the Vault processor,
     * this way we can piggy-back on the CiviCRM core forms for updating CC
     * credentials.
     *
     * DOCUMENT: Assumes only one vault is enabled.
     */
    $vault_processor = civicrm_api3('PaymentProcessor', 'getsingle', [
      'class_name' => 'Payment_Vault',
      'is_active' => 1,
      'is_test' => 0,
    ]);

    civicrm_api3('ContributionRecur', 'create', [
      'contact_id' => $params['contact_id'],
      'frequency_interval' => '1',
      'frequency_unit' => 'year',
      'amount' => '1' . sprintf('%02d', mt_rand(1,99)),
      'contribution_status_id' => 2,
      'start_date' => date('Y-m-d H:i:s'),
      'currency' => $params['currency'],
      'payment_processor_id' => $vault_processor['id'],
      'processor_id' => $processor_id,
    ]);

    Civi::log()->info(ts('Card saved to vault for Contact %1', [
      1 => $params['contact_id'],
    ]));
  }
}
