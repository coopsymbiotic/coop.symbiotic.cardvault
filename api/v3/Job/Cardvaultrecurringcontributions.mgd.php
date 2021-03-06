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
    'name' => 'Cron:Job.Cardvaultrecurringcontributions',
    'entity' => 'Job',
    'params' =>
    array(
      'version' => 3,
      'name' => 'Cardvault Process Recurring Contributions',
      'description' => 'Process recurring contributions using Cardvault data',
      'run_frequency' => 'Yearly',
      'api_entity' => 'Job',
      'api_action' => 'cardvaultrecurringcontributions',
      'parameters' => '',
    ),
    'update' => 'never',
  ),
);
