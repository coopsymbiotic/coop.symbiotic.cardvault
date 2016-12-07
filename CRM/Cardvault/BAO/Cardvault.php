<?php

class CRM_Cardvault_BAO_Cardvault {

  /**
   *
   * Retrieve credit card number for printing on receipts, and other places that need to "display" them
   *
   * Note that we never return a full credit ard number
   *
   * @param $contributionId
   * @return null|string
   */
  public static function getCCInfo($contributionId) {
    $sql = "SELECT ccinfo 
              FROM civicrm_contribution c, civicrm_cardvault v
             WHERE c.id = %1
               AND c.contact_id = v.contact_id
               AND c.invoice_id collate utf8_general_ci = v.invoice_id collate utf8_general_ci
               ";
    $sqlParams = [ 1 => [$contributionId, 'Positive']];
    $dao = CRM_Core_DAO::executeQuery($sql, $sqlParams);
    if (!$dao->fetch()) {
      return NULL;
    }

    $crypt = new CRM_Cardvault_Encrypt();
    $cc = $crypt->decrypt($dao->ccinfo);

    $ccSafeNumber = str_repeat("*", strlen($cc['number']) - 4) . substr($cc['number'], strlen($cc['number']) - 4);

    return [
      'cc_type' => $cc['type'],
      'cc_number' => $ccSafeNumber
    ];
  }

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

    // possible to have NULLs in other parameters (e.g., conversion)
    // so no further validation.

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

    // NB: this contribution_id/invoice_id might not exist if backend form
    // but that's OK, because we always associated 1 card = 1 transaction
    // (in case they want to update one card for a payment, but not the other)
    if (CRM_Cardvault_Utils::card_hash_exists($hash, $params['contact_id'], $params['invoice_id'])) {
      Civi::log()->info(ts('Card already in vault for Contact %1, Invoice %2', [
        1 => $params['contact_id'],
        2 => $params['invoice_id'],
      ]));
      return;
    }

    // civicrm_contribution_recur.processor_id is the external ID for the payment processor
    // Required for editing the payment info.
    $processor_id = NULL;

    // Note contribution_id may be NULL in front end.  But invoice_id should always be present
    // We keep contribution_id for convenience, but really, we should always use invoice_id
    // TODO: Consider removing contribution_id altogether
    $sqlParams = [
      1 => [$params['contact_id'], 'Positive'],
      2 => [$cc, 'String'],
      3 => [$hash, 'String'],
    ];
    if (!empty($params['contribution_id'])) {
      // both contribution always set with invoice.  So if contribution specified, so should invoice
      $sql = "INSERT INTO civicrm_cardvault(contact_id, ccinfo, hash, invoice_id, contribution_id) VALUES(%1, %2, %3, %4, %5)";
      $sqlParams[4] = [$params['invoice_id'], 'String'];
      $sqlParams[5] = [$params['contribution_id'], 'Positive'];
    } else if (!empty($params['invoice_id'])) {
      // invoice should always be specified, except for conversions.
      $sql = "INSERT INTO civicrm_cardvault(contact_id, ccinfo, hash, invoice_id) VALUES(%1, %2, %3, %4)";
      $sqlParams[4] = [$params['invoice_id'], 'String'];
    } else {
      $sql = "INSERT INTO civicrm_cardvault(contact_id, ccinfo, hash) VALUES(%1, %2, %3)";
    }
    CRM_Core_DAO::executeQuery($sql, $sqlParams);

    // imports & conversions may not have invoice_id, in which case, pick most recent... well for that, just always pick most recent for contat & card!
    $processor_id = CRM_Core_DAO::singleValueQuery('SELECT id FROM civicrm_cardvault WHERE contact_id = %1 AND hash = %2 ORDER BY created_date DESC ', [
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

