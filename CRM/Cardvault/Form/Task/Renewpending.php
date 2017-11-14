<?php

class CRM_Cardvault_Form_Task_Renewpending extends CRM_Member_Form_Task {

  /**
   * Are we operating in "single mode", i.e. deleting one
   * specific membership?
   *
   * @var boolean
   */
  protected $_single = FALSE;

  /**
   * Build all the data structures needed to build the form.
   *
   * @return void
   */
  public function preProcess() {
    if (!CRM_Core_Permission::check('edit contributions')) {
      CRM_Core_Error::fatal(ts('You do not have permission to access this page.'));
    }
    parent::preProcess();
  }

  /**
   * Build the form object.
   *
   * @return void
   */
  public function buildQuickForm() {
    $this->addDefaultButtons(ts('Process Memberships'), 'done');

    // TODO: display the actual list of contributions?
  }

  /**
   * Process the form after the input has been submitted and validated.
   *
   *
   * @return void
   */
  public function postProcess() {
    $success = $fail = 0;

    foreach ($this->_memberIds as $membership_id) {
      // Fetch pending contributions (there should only be one)
      $result = civicrm_api3('MembershipPayment', 'get', [
        'membership_id' => $membership_id,
        'contribution_id.contribution_status_id' => 'Pending',
        'api.contribution.get' => [
          'sequential' => 1,
        ],
      ]);

      foreach ($result['values'] as $key => $val) {
        if ($val['api.contribution.get']['values'][0]['total_amount'] > 0) {
          try {
            $contribution_id = $val['contribution_id'];
            $charge_result = CRM_Cardvault_BAO_Cardvault::charge($contribution_id);

            $success++;
          }
          catch (Exception $e) {
            $fail++;

            civicrm_api3('Contribution', 'create', [
              'id' => $contribution_id,
              'contribution_status_id' => 'Failed',
            ]);

            civicrm_api3('Note', 'create', [
              'entity_table' => 'civicrm_contact',
              'entity_id' => $val['api.contribution.get']['values'][0]['contact_id'],
              'note' => 'Cardvault failed to process the credit card: ' . $e->getMessage(),
            ]);
          }
        }
      }

    }

    if ($success) {
      $msg = ts('%count membership processed.', array('plural' => '%count memberships processed.', 'count' => $success));
      CRM_Core_Session::setStatus($msg, ts('Processed'), 'success');
    }

    if ($fail) {
      CRM_Core_Session::setStatus(ts('1 could not be processed.', array('plural' => '%count could not be processed.', 'count' => $fail)), ts('Error'), 'error');
    }
  }

}
