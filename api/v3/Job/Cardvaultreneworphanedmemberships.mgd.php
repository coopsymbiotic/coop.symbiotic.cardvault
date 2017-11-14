<?php

/**
 * @file
 * This file declares a managed database record of type "Job".
 */

// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// https://docs.civicrm.org/dev/en/master/hooks/
return array(
  0 =>
  array(
    'name' => 'Cron:Job.Cardvaultreneworphanedmemberships',
    'entity' => 'Job',
    'params' =>
    array(
      'version' => 3,
      'name' => 'Cardvault Rewnew Orphaned Memberships',
      'description' => 'Renew orphaned memberships (without a contribution, 0$)',
      'run_frequency' => 'Yearly',
      'api_entity' => 'Job',
      'api_action' => 'cardvaultrenewmembership',
      'parameters' => '',
    ),
    'update' => 'never',
  ),
);
