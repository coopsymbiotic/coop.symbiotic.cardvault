<?php

/**
 * Job.CardvaultRenewOrphanedMemberships API.
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 *
 * Parameters:
 *   payment_processor_id (required): use the correct ID for live or test.
 *   payment_processor_mode (required): live or test
 *   recur_id (optional): only process a specific recurring contribution ID.
 *
 * This is similar to Job.Cardvaultrenewmemberships, except that it handles
 * specifically memberships that do not have any associated contribution
 * (legacy post-migration from the previous CRM).
 */
function civicrm_api3_job_cardvaultreneworphanedmemberships($params) {
  // Running this job in parallell could generate bad duplicate contributions.
  $lock = new CRM_Core_Lock('civicrm.job.cardvaultreneworphanedmemberships');

  if (!$lock->acquire()) {
    return civicrm_api3_create_success(ts('Failed to acquire lock. No orphaned memberships were renewed.'));
  }

  $sqlparams = [];

  $require_cardvault = FALSE;
  $cardvault_sql = '';

  // NB: we only support "has_cardvault=1", not "has_cardvault=0".
  if (!empty($params['has_cardvault'])) {
    $cardvault_sql = ' inner join civicrm_cardvault cv on cv.contact_id = m1.contact_id ';
  }

  // contribution_status_id: 2=Pending, 5=InProgress
  $sql = "
      SELECT m1.id as membership_id, m1.contact_id, " . ($cardvault_sql ? 'max(cv.id) as max_cv_id' : '0 as max_cv_id') . "
        FROM civicrm_membership m1
        $cardvault_sql
        LEFT JOIN civicrm_contact contact ON (contact.id = m1.contact_id)
        WHERE NOT exists(SELECT *
                     FROM civicrm_contribution c
                     WHERE c.contact_id = m1.contact_id)
          AND NOT exists(SELECT *
                         FROM civicrm_membership m2
                         WHERE m2.contact_id = m1.contact_id AND m2.end_date > m1.end_date)
          AND m1.end_date = '2017-12-31' AND m1.status_id = 3 AND contact.is_deceased = 0
  ";

  // in case the job was called to execute a specific contact id
  if (!empty($params['contact_id'])) {
    $sql .= ' AND m1.contact_id = %1';
    $sqlparams[1] = [$params['contact_id'], 'Positive'];
  }
  $sql .= ' GROUP BY m1.contact_id';

  $dao = CRM_Core_DAO::executeQuery($sql, $sqlparams);
  $contribution = NULL;
  $province = NULL;

  while ($dao->fetch()) {
    if (isset($params['cic_autorenew'])) {
      // This is weird, but we check for the autorenew of all renewal-ready memberships
      // and prioritize (order by) the "autorenew = yes".
      $is_renew_membership = CRM_Core_DAO::singleValueQuery('SELECT renew_membership_52 FROM civicrm_membership m LEFT JOIN civicrm_value_membership_extras_7 extras ON (extras.entity_id = m.id) WHERE m.contact_id = %1 AND m.status_id = 3 ORDER BY renew_membership_52 DESC LIMIT 1', [
        1 => [$dao->contact_id, 'Positive'],
      ]);

      if (empty($is_renew_membership)) {
        $is_renew_membership = 0;
      }

      if ($is_renew_membership != $params['cic_autorenew']) {
drush_log('COUCOU 4a', 'ok');
        continue;
      }
    }
drush_log('COUCOU 4b', 'ok');

    $contact_id = $dao->contact_id;
    $cv_id = $dao->max_cv_id;

    // NB: [ML] this fetches the cardvault entry for the CC type (instrument)
    // but for non-cardvault transactions, we will default to paying by cheque,
    // so I replaced the "INNER JOIN" by a "LEFT JOIN".
    $sql2 = "select m.id as membership_id, m.contact_id,
       m.membership_type_id,
       mt.name as label,
       mt.minimum_fee,
       mt.financial_type_id,
       m.id as membership_id,
       ov.value as payment_instrument_id
  from civicrm_membership m
      left join civicrm_cardvault cv on cv.contact_id = $contact_id and cv.id = $cv_id
      inner join civicrm_membership_type mt on mt.id = m.membership_type_id
      left join civicrm_option_value ov on ov.option_group_id = 10 and ov.name = cv.cc_type
  where m.contact_id = $contact_id
    and m.end_date = '2017-12-31'
    and m.status_id = 3
  order by m.contact_id,
          mt.minimum_fee desc,
          case when mt.name like '%ACCN%' then 1 else 0 end,
      mt.id";

    $row = CRM_Core_DAO::executeQuery($sql2)->fetchAll();

    $contact = civicrm_api3('Contact', 'get', array(
      'sequential' => 1,
      'id' => $dao->contact_id
    ))['values'][0];

    $total_amount = 0;
    $pii = 1; // Credit Card

    if (isset($params['cic_autorenew']) && empty($params['cic_autorenew'])) {
      $pii = 4; // Cheque
    }

    foreach ($row as $mem_row) {
      $total_amount = $total_amount + $mem_row['minimum_fee'];
      if (!empty($mem_row['payment_instrument_id'])) {
        $pii = $mem_row['payment_instrument_id'];
      }
    }

    $taxes = calculateTaxes($contact, $total_amount);
    $contribution = new CRM_Contribute_BAO_Contribution();

    // Set the contribution status to Pending, since we are not charging yet
    // and receive_date (for now) set to 'today', even if we haven't charged it yet,
    // but we will update this in the API call that processes this.
    $contribution->contribution_status_id = 2; // Pending
    $contribution->receive_date = date('YmdHis'); // Today
    $contribution->financial_type_id = 237; // FIXME hardcoded
    $contribution->contact_id = $dao->contact_id;
    $contribution->payment_instrument_id = $pii;
    $contribution->currency = 'CAD';
    $contribution->source = '2017 contributionless membership renewal'; // FIXME: hardcoded
    $contribution->is_test = 0;
    $contribution->is_pay_later = 0;
    $contribution->tax_amount = 0; // we don't use civicrm taxes.
    $contribution->total_amount = $total_amount + $taxes;
    $contribution->fee_amount = 0;
    $contribution->net_amount = $total_amount + $taxes;

    $contribution->save();

    foreach ($row as $mem_row) {
      $t = civicrm_api3('LineItem', 'create', [
        'entity_table' => 'civicrm_contribution',
        'entity_id' => $contribution->id,
        'contribution_id' => $contribution->id,
        'label' => $mem_row['label'],
        'qty' => 1,
        'unit_price' => $mem_row['minimum_fee'],
        'line_total' => $mem_row['minimum_fee'],
        'financial_type_id' => $mem_row['financial_type_id'],
        'deductible_amount' => 0
      ]);

      $t = civicrm_api3('LineItem', 'create', [
        'entity_table' => 'civicrm_membership',
        'entity_id' => $mem_row['membership_id'],
        'contribution_id' => $contribution->id,
        'label' => $mem_row['label'],
        'qty' => 1,
        'unit_price' => $mem_row['minimum_fee'],
        'line_total' => $mem_row['minimum_fee'],
        'financial_type_id' => $mem_row['financial_type_id'],
        'deductible_amount' => 0
      ]);
    }

    if ($taxes > 0) {
      // THe tax entry
      $t = civicrm_api3('LineItem', 'create', [
        'entity_table' => 'civicrm_contribution',
        'entity_id' => $contribution->id,
        'contribution_id' => $contribution->id,
        'label' => 'hidden_taxes',
        'qty' => $taxes,
        'unit_price' => 1,
        'line_total' => $taxes,
        'financial_type_id' => 274,
        'deductible_amount' => 0
      ]);
    }

    foreach ($row as $mem_row) {
      civicrm_api3('MembershipPayment', 'create', [
        'contribution_id' => $contribution->id,
        'membership_id' => $mem_row['membership_id'],
      ]);
    }
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
