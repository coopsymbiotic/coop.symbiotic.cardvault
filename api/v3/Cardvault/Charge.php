<?php

/**
 * Cardvault.charge API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_cardvault_charge($params) {
  $result = [
    'values' => [],
  ];

  $result['values'] = CRM_Cardvault_BAO_Cardvault::charge($params['contribution_id']);

  return $result;
}
