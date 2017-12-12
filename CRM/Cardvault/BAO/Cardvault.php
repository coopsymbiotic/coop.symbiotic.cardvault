<?php

class CRM_Cardvault_BAO_Cardvault {

  /**
   *
   * Retrieve credit card number for printing on receipts, and other places that need to "display" them
   *
   * Note that we never return a full credit card number
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

    $ccSafeNumber = self::obfuscateCCnumber($cc['number']);

    return [
      'cc_type' => $cc['type'],
      'cc_number' => $ccSafeNumber
    ];
  }

  /**
   * Given a CC number 4111111111111111, returns ************1111.
   */
  public static function obfuscateCCnumber($number) {
    return str_repeat("*", strlen($number) - 4) . substr($number, strlen($number) - 4);
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
    $expiry = $ccinfo['year'] . sprintf('%02d', $ccinfo['month']) . '01';
    $mask = self::obfuscateCCnumber($ccinfo['number']);

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
      6 => [$ccinfo['type'], 'String'],
      7 => [$expiry, 'Timestamp'],
      8 => [$mask, 'String'],
    ];

    if (!empty($params['contribution_id'])) {
      // both contribution always set with invoice.  So if contribution specified, so should invoice
      $sql = "INSERT INTO civicrm_cardvault(contact_id, ccinfo, hash, invoice_id, contribution_id, cc_type, expiry_date, masked_account_number) VALUES(%1, %2, %3, %4, %5, %6, %7, %8)";
      $sqlParams[4] = [$params['invoice_id'], 'String'];
      $sqlParams[5] = [$params['contribution_id'], 'Positive'];
    }
    elseif (!empty($params['invoice_id'])) {
      // invoice should always be specified, except for conversions.
      $sql = "INSERT INTO civicrm_cardvault(contact_id, ccinfo, hash, invoice_id, cc_type, expiry_date, masked_account_number) VALUES(%1, %2, %3, %4, %6, %7, %8)";
      $sqlParams[4] = [$params['invoice_id'], 'String'];
    }
    else {
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

  /**
   * Charges a card in the vault.
   */
  public static function charge($contribution_id) {
    $contribution = civicrm_api3('Contribution', 'getsingle', [
      'id' => $contribution_id,
    ]);

    if ($contribution['contribution_status_id'] == 1) {
      throw new Exception("The contribution cannot be charged, it is already set as 'completed'.");
    }

    $dao = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_cardvault WHERE contact_id = %1 ORDER BY created_date DESC', [
      1 => [$contribution['contact_id'], 'Positive'],
    ]);

    if (!$dao->fetch()) {
      throw new Exception("No card was found on file.");
    }

    // FIXME: needs refactoring
    $crypt = new CRM_Cardvault_Encrypt();
    $cc = $crypt->decrypt($dao->ccinfo);

    // Generate a new invoice ID for this new contribution
    // civicrm_api3_contribution_transact() does somethign similar.
    $invoice_id = sha1(uniqid(rand(), TRUE));

    $contact = civicrm_api3('Contact', 'getsingle', [
      'id' => $contribution['contact_id'],
    ]);

    $payment_params = [
      'is_from_cardvault' => TRUE,
      'contactID' => $contribution['contact_id'],
      'billing_first_name' => $contact['first_name'],
      'billing_last_name' => $contact['last_name'],
      'amount' => $contribution['total_amount'],
      'currencyID' => $contribution['currency'],
      'invoiceID' => $invoice_id,
      'invoice_id' => $invoice_id,
      'credit_card_number' => $cc['number'],
      'cvv2' => $cc['cvv2'],
      'street_address' => $contact['street_address'],
      'city' => $contact['city'],
      'state_province' => $contact['state_province'],
      'country' => $contact['country'],
    ];

    $result = [];
    $result['payment_status_id'] = 1; // Completed

    $payment_processor_id = 3; // FIXME
    $payment_processor_mode = ($contribution['is_test'] ? 'test' : 'live');

    $paymentProcessor = CRM_Financial_BAO_PaymentProcessor::getPayment($payment_processor_id, $payment_processor_mode);

    try {
      $t = $paymentProcessor['object']->doPayment($payment_params);
    }
    catch (Exception $e) {
      Civi::log()->error('Cardvault: failed payment: ' . $e->getMessage());
      $result['error_message'] = $e->getMessage();
      $result['payment_status_id'] = 4; // Failed

      civicrm_api3('Note', 'create', [
        'entity_table' => 'civicrm_contact',
        'entity_id' => $contribution['contact_id'],
        'note' => 'Cardvault failed to process the credit card: ' . $e->getMessage(),
      ]);
    }

    $result['trxn_id'] = $t['trxn_id'];
    $result['invoice_id'] = $invoice_id;
    $result['misc'] = $t;

    // Update the contribution to 'complete' and save the invoice and trxn_id
    if ($result['payment_status_id'] == 1) {
      civicrm_api3('Contribution', 'create', [
        'id' => $contribution_id,
        'invoice_id' => $invoice_id,
        'trxn_id' => $t['trxn_id'],
      ]);

      civicrm_api3('Contribution', 'completetransaction', [
        'id' => $contribution_id,
        'is_email_receipt' => 1,
      ]);
    }

    return $result;
  }

  /**
   * Set a contribution as failed and log a note.
   */
  public static function failContribution($contribution_id, $contact_id, $message) {
    civicrm_api3('Contribution', 'create', [
      'id' => $contribution_id,
      'contribution_status_id' => 'Failed',
    ]);

    $contact_id = civicrm_api3('Contribution', 'getvalue', [
      'id' => $contribution_id,
      'return' => 'contact_id',
    ]);

    civicrm_api3('Note', 'create', [
      'entity_table' => 'civicrm_contact',
      'entity_id' => $contact_id,
      'note' => 'Cardvault failed to process the credit card: ' . $message,
    ]);
  }

}

