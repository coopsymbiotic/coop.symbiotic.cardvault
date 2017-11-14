<?php

/**
 * Job.Cardvaultrenewmemberships API.
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
function civicrm_api3_job_cardvaultrenewmemberships($params) {
  // Running this job in parallell could generate bad duplicate contributions.
  $lock = new CRM_Core_Lock('civicrm.job.cardvaultrenewmemberships');

  if (!$lock->acquire()) {
    return civicrm_api3_create_success(ts('Failed to acquire lock. No memberships were renewed.'));
  }

  $sqlparams = [];

  // contribution_status_id: 2=Pending, 5=InProgress
  $sql = 'SELECT m.contact_id,
            contrib.id as contribution_id, contrib.contribution_status_id,
            contrib.total_amount, contrib.currency, contrib.invoice_id
      FROM civicrm_membership m
      LEFT JOIN civicrm_membership_payment mp ON (mp.membership_id = m.id)
      LEFT JOIN civicrm_contribution contrib ON (contrib.id = mp.contribution_id)
      WHERE m.status_id = 3'; // FIXME hardcoded status (renewal ready).  I think this is ok (Marc).

  // FIXME: Only do things that have a corresponding cc vault entry for the contact

  // in case the job was called to execute a specific contact id
  if (!empty($params['contact_id'])) {
    $sql .= ' AND m.contact_id = %1';
    $sqlparams[1] = [$params['contact_id'], 'Positive'];
  }

  // There can be multiple memberships for a given contribution
  $sql .= ' ORDER BY m.contact_id, contrib.id DESC';

  $counter = 0;
  $error_count = 0;
  $output = [];
  $last_contact_id = 0;

  $dao = CRM_Core_DAO::executeQuery($sql, $sqlparams);

  while ($dao->fetch()) {
    $contribution_id = $dao->contribution_id;
    if ($last_contact_id == $dao->contact_id) {
      continue;
    }
    $last_contact_id = $dao->contact_id;

    // FIXME: XXX: If no contribution was associated with the membership,
    // SEE Cardvaultrenewoprhanedmemberships
    // assume it's a migration error and the two were not correctly associated.
    // Fetch the latest contribution for the contact.
    if (empty($contribution_id)) {
      $old_contributions = civicrm_api3('Contribution', 'get', [
        'contact_id' => $dao->contact_id,
        'sort' => 'receive_date DESC',
        'sequential' => 1,
      ]);

      if ($old_contributions['count'] == 0) {
        Civi::log()->warning('NO CONTRIBUTIONS FOUND for ' . $dao->contact_id);
        continue;
      }
      elseif ($old_contributions['count'] == 1) {
        $contribution_id = $old_contributions['values'][0]['id'];
        Civi::log()->info('OK: Using contribution ID: ' . $contribution_id);
      }
      else {
        Civi::log()->warning('Contact ' . $dao->contact_id . ': ' . print_r($result['values'], 1));
        continue;
      }
    }

    // Copied from civicrm_api3_contribution_repeattransaction(),
    // but without assuming that the contribution is part of a recurring series.
    $contribution = new CRM_Contribute_BAO_Contribution();
    $contribution->id = $contribution_id;

    if (!$contribution->find(TRUE)) {
      throw new API_Exception('A valid original contribution ID is required', 'invalid_data');
    }

    $original_contribution = clone $contribution;
    $ids = $input = [];

    // Normally we would load the previously used PP, but in many cases
    // the old contribution won't be correctly associated anyway.
    $input['payment_processor_id'] = $params['payment_processor_id'];

    // FIXME: is it necessary? This was done to eventually charge the CC,
    // but also in hope that it magically fixed FinancialItem stuff.
    // FIXME: IF it is, then I'm missing something in cardvaultreneworphanedmemberships.php
    if (!$contribution->loadRelatedObjects($input, $ids, TRUE)) {
      throw new API_Exception('failed to load related objects');
    }

    unset($contribution->id, $contribution->receive_date, $contribution->invoice_id, $contribution->trxn_id);

    // Set the contribution status to Pending, since we are not charging yet
    // and receive_date (for now) set to 'today', even if we haven't charged it yet,
    // but we will update this in the API call that processes this.
    $contribution->contribution_status_id = 2; // Pending
    $contribution->receive_date = date('YmdHis'); // Today
    $contribution->save();

    $lineitem_result = civicrm_api3('LineItem', 'get', [
      'sequential' => 1,
      'entity_table' => 'civicrm_contribution',
      'entity_id' => $original_contribution->id,
    ]);

    foreach ($lineitem_result['values'] as $original_line_item) {
      // FIXME: If 'hidden_tax', then skip
      $t = civicrm_api3('LineItem', 'create', [
        'entity_table' => 'civicrm_contribution',
        'entity_id' => $contribution->id,
        'contribution_id' => $contribution->id,
        'price_field_id' => $original_line_item['price_field_id'],
        'label' => $original_line_item['label'],
        'qty' => $original_line_item['qty'],
        // FIXME: Use minimum_value from membership_type (if applicable)
        'unit_price' => $original_line_item['unit_price'],
        // FIXME: Use minimum_value from membership_type (if applicable)
        'line_total' => $original_line_item['line_total'],
        'participant_count' => $original_line_item['participant_count'],
        'price_field_value_id' => $original_line_item['price_field_value_id'],
        'financial_type_id' => $original_line_item['financial_type_id'],
        'deductible_amount' => $original_line_item['deductible_amount'],
      ]);

      // FIXME: If total amount > 0, then recalculate taxes, and create hiden_tax entry skipped above.
    }

    // Fetch all memberships for this contribution and associate the (future) contribution
    $sql2 = 'SELECT m.contact_id, m.id as membership_id
        FROM civicrm_membership m
        LEFT JOIN civicrm_membership_payment mp ON (mp.membership_id = m.id)
        LEFT JOIN civicrm_contribution contrib ON (contrib.id = mp.contribution_id)
        WHERE m.contact_id = %1
          AND m.status_id = 3'; // FIXME hardcoded status (renewal ready)

    $dao2 = CRM_Core_DAO::executeQuery($sql2, [
      1 => [$dao->contact_id, 'Positive'],
    ]);

    while ($dao2->fetch()) {
      civicrm_api3('MembershipPayment', 'create', [
        'contribution_id' => $contribution->id,
        'membership_id' => $dao2->membership_id,
      ]);
    }

/*
    $cc = $crypt->decrypt($dao->ccinfo);

    $payment_params = [
      'is_from_cardvault' => TRUE,
      'contactID' => $dao->contact_id,
      'billing_first_name' => '',
      'billing_last_name' => '',
      'amount' => $dao->total_amount,
      'currencyID' => $dao->currency,
      'invoiceID' => $invoice_id,
      'invoice_id' => $invoice_id,
      'credit_card_number' => $cc['number'],
      'cvv2' => $cc['cvv2'],
      'street_address' => '',
      'city' => '',
      'state_province' => '',
      'country' => '',
    ];

    $payment_status_id = 1; // Completed

    try {
      $t = $paymentProcessor['object']->doPayment($payment_params);
    }
    catch (PaymentProcessorException $e) {
      Civi::log()->error('Cardvault: failed payment: ' . $e->getMessage());
      $payment_status_id = 4; // Failed
    }

    // The original contribution was already completed
    $result = civicrm_api3('Contribution', 'repeattransaction', [
      'original_contribution_id' => $dao->contribution_id,
      'contribution_status_id' => $payment_status_id,
      'invoice_id' => $invoice_id,
      'trxn_result_code' => $payment_params['trxn_result_code'],
      'trxn_id' => $payment_params['trxn_id'],
      'payment_processor_id' => $payment_processor_id,
      // 'is_email_receipt' => TRUE, // FIXME test & make configurable?
    ]);

    // Presumably there is a good reason why CiviCRM is not storing
    // our new invoice_id. Anyone know?
    $contribution_id = $result['id'];

    civicrm_api3('Contribution', 'create', [
      'id' => $contribution_id,
      'invoice_id' => $invoice_id,
    ]);

*/
  }

}


function calculateTaxes($contact, $total_amount) {
  return $total_amount * getTaxRate($contact);
}

function getTaxRate($contact) {
  switch ($contact->state_province_id) {
    case "1100":
      return 0.05; // Alberta
    case "1101":
      return 0.05; // 0.12; // British Columbia
    case "1102":
      return 0.05; // 0.13; // Manitoba
    case "1103":
      return 0.15; // New-Brunswick
    case "1104":
      return 0.15; // Newfoundland and Labrador
    case "1105":
      return 0.05; // Northwest Territories
    case "1106":
      return 0.15; // Nova Scotia
    case "1107":
      return 0.05; // Nunavut
    case "1108":
      return 0.13; // Ontario
    case "1109":
      return 0.14; // Prince Edward Island
    case "1110":
      return 0.05; // 0.14975; // Quebec
    case "1111":
      return 0.05; // 0.10; // Saskatchewan
    case "1112":
      return 0.05; // Yukon Territory
    default:
      return 0.00;
  }
}
