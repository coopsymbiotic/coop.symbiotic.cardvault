<?php

class CRM_Cardvault_Form_Task_ChargePending extends CRM_Contribute_Form_Task {

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
    $this->addDefaultButtons(ts('Process Contributions'), 'done');

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

    foreach ($this->_contributionIds as $contribution_id) {
      try {
        $charge_result = CRM_Cardvault_BAO_Cardvault::charge($contribution_id);
      
        if ($charge_result['payment_status_id'] == 4) {
          $fail++;
          CRM_Cardvault_BAO_Cardvault::failContribution($contribution_id, $charge_result['error_message']);
        }
        else {
          $success++;
        }
      }
      catch (Exception $e) {
        $fail++;
        CRM_Cardvault_BAO_Cardvault::failContribution($contribution_id, $e->getMessage());
      }
    }

    if ($success) {
      $msg = ts('%count contribution processed.', array('plural' => '%count contributions processed.', 'count' => $success));
      CRM_Core_Session::setStatus($msg, ts('Processed'), 'success');
    }

    if ($fail) {
      CRM_Core_Session::setStatus(ts('1 could not be processed.', array('plural' => '%count could not be processed.', 'count' => $fail)), ts('Error'), 'error');
    }
  }

}
