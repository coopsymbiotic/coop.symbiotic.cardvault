<?php

/**
 * Job.CardvaultrecurringContributions API.
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 *
 * Parameters:
 *   payment_processor_id (required): use the correct ID for live or test.
 *   payment_processor_mode (required): live or test
 *   recur_id (optional): only process a specific recurring contribution ID.
 */
function civicrm_api3_job_cardvaultrecurringcontributions($params) {
  // Running this job in parallell could generate bad duplicate contributions.
  $lock = new CRM_Core_Lock('civicrm.job.cardvaultrecurringcontributions');

  if (!$lock->acquire()) {
    return civicrm_api3_create_success(ts('Failed to acquire lock. No contribution records were processed.'));
  }

  $sqlparams = [];

  // contribution_status_id: 2=Pending, 5=InProgress
  $sql = 'SELECT cr.id as recur_id, vault.contact_id, vault.ccinfo,
            contrib.id as original_contribution_id, contrib.contribution_status_id,
            contrib.total_amount, contrib.currency, contrib.invoice_id
      FROM civicrm_contribution_recur cr
      INNER JOIN civicrm_contribution contrib ON (contrib.contribution_recur_id = cr.id)
      INNER JOIN civicrm_cardvault vault ON (vault.contribution_id = contrib.id)
      WHERE cr.contribution_status_id IN (2,5)';

  // in case the job was called to execute a specific recurring contribution id -- not yet implemented!
  if (!empty($params['recur_id'])) {
    $sql .= ' AND cr.id = %1';
    $sqlparams[1] = [$params['recur_id'], 'Positive'];
  }
  else {
    // normally, process all recurring contributions due today or earlier.
    // FIXME: normally we should use '=', unless catching up.
    // If catching up, we need to manually update the next_sched_contribution_date
    // because CRM_Contribute_BAO_ContributionRecur::updateOnNewPayment() only updates
    // if the receive_date = next_sched_contribution_date.
    $sql .= ' AND (cr.next_sched_contribution_date <= CURDATE()
                      OR (cr.next_sched_contribution_date IS NULL AND cr.start_date <= CURDATE()))';
  }

  $crypt = new CRM_Cardvault_Encrypt();
  $paymentProcessor = CRM_Financial_BAO_PaymentProcessor::getPayment($params['payment_processor_id'], $params['payment_processor_mode']);

  $counter = 0;
  $error_count = 0;
  $output = [];

  $dao = CRM_Core_DAO::executeQuery($sql, $sqlparams);

  while ($dao->fetch()) {
    $cc = $crypt->decrypt($dao->ccinfo);

    // If the initial contribution is pending (2), then we use that
    // invoice_id for the payment processor. Otherwise we generate a new one.
    $invoice_id = NULL;

    if ($dao->contribution_status_id == 2) {
      $invoice_id = $dao->invoice_id;
    }
    else {
      // ex: civicrm_api3_contribution_transact() does somethign similar.
      $invoice_id = sha1(uniqid(rand(), TRUE));
    }

    // Investigate whether we can use the Contribution.transact API call?
    // it seemed a bit trickier to use, because of pricesets/amounts, more lifting
    // than just calling 'repeattransaction'.
    $payment_params = [
      'is_from_cardvault' => TRUE,
      'contactID' => $dao->contact_id,
      'billing_first_name' => '',
      'billing_last_name' => '',
      'amount' => $dao->total_amount,
      'currencyID' => $dao->currency,
      'invoiceID' => $invoice_id,
      'credit_card_number' => $cc['number'],
      'cvv2' => $cc['cvv2'],
      'street_address' => '',
      'city' => '',
      'state_province' => '',
      'country' => '',
    ];

    $payment_status_id = 1; // Completed

    try {
      $paymentProcessor['object']->doPayment($payment_params);
    }
    catch (PaymentProcessorException $e) {
      Civi::log()->error('Cardvault: failed payment: ' . $e->getMessage());
      $payment_status_id = 4; // Failed
    }

    if ($dao->contribution_status_id == 1) {
      // The original contribution was already completed
      $result = civicrm_api3('Contribution', 'repeattransaction', [
        'contribution_recur_id' => $dao->recur_id,
        'original_contribution_id' => $dao->original_contribution_id,
        'contribution_status_id' => $payment_status_id,
        'invoice_id' => $invoice_id,
        'trxn_result_code' => $payment_params['trxn_result_code'],
        'trxn_id' => $payment_params['trxn_id'],
      ]);

      // Presumably there is a good reason why CiviCRM is not storing
      // our new invoice_id. Anyone know?
      $contribution_id = $result['id'];

      civicrm_api3('Contribution', 'create', [
        'id' => $contribution_id,
        'invoice_id' => $invoice_id,
      ]);
    }
    elseif ($dao->contribution_status_id == 2) {
      // The original contrib is pending, so complete it,
      // otherwise it'll forever stay "pending".
      civicrm_api3('Contribution', 'completetransaction', [
        'id' => $dao->original_contribution_id,
        'contribution_recur_id' => $dao->recur_id,
        'original_contribution_id' => $dao->original_contribution_id,
        'contribution_status_id' => $payment_status_id,
        'trxn_result_code' => $payment_params['trxn_result_code'],
        'trxn_id' => $payment_params['trxn_id'],
      ]);
    }
    else {
      Civi::log()->warning("Cardvault: contribution ID {$dao->original_contribution_id} has an unexpected status: {$dao->contribution_status_id} -- skipping renewal.");
    }
  }

}
