<?php

// Based on:
// https://github.com/civicrm/civihr/blob/b1bf0c25c0ca4e57ce147bd59e8b9f619d26b5d2/com.civicrm.hrjobroles/CRM/Hrjobroles/BAO/Query.php

class CRM_Cardvault_BAO_Query extends CRM_Contact_BAO_Query_Interface {

  public function &getFields() {
    // Normally we would use the DAO exportFields, but Cardvault does not yet implement it.
    // It's not really clear how the Query builder uses this.
    $fields = [
      'cardvault_masked_account_number' => array(
        'name' => 'masked_account_number',
        'type' => CRM_Utils_Type::T_STRING,
        'data_type' => CRM_Utils_Type::T_STRING,
        'title' => ts('Masked Account Number') ,
        'description' => 'Masked Account Number',
        'required' => false,
        'table_name' => 'civicrm_cardvault',
        'localizable' => 0,
      ),
      'cardvault_expiry_date' => array(
        'name' => 'expiry_date',
        'type' => CRM_Utils_Type::T_DATE,
        'title' => ts('Expiry Date') ,
        'description' => 'Card Expiry Date',
        'required' => false,
        'table_name' => 'civicrm_cardvault',
        'localizable' => 0,
      ),
    ];

    return $fields;
  }

  /**
   * @param $fieldName
   * @param $mode
   * @param $side
   *
   * @return mixed
   */
  public function from($name, $mode, $side) {
    $from = '';
    switch ($name) {
      case 'civicrm_contact':
        $from .= " $side JOIN civicrm_cardvault ON contact_a.id = civicrm_cardvault.contact_id ";
        break;
    }
    return $from;
  }

  public function where(&$query) {
    $grouping = NULL;
    foreach ($query->_params as $param) {
      if ($this->isACardvaultParam($param)) {
        if ($query->_mode == CRM_Contact_BAO_QUERY::MODE_CONTACTS) {
          $query->_useDistinct = TRUE;
        }
        $this->whereClauseSingle($param, $query);
      }
    }
  }

  private function isACardvaultParam($param) {
    $paramHasName = isset($param[0]) && !empty($param[0]);
    if ($paramHasName && substr($param[0], 0, 9) == 'cardvault') {
      return TRUE;
    }
    return FALSE;
  }

  private function whereClauseSingle($values, &$query) {
    list($name, $op, $value, $grouping, $wildcard) = $values;

    $fields = $this->getFields();

    // Ex: "cardvault_masked_account_number" becomes "masked_account_number".
    $field = substr($name, 10);
    $whereField = 'civicrm_cardvault.' . $field;
    $fieldTitle = $field;

    if (!empty($fields[$name]['title'])) {
      $fieldTitle = $fields[$name]['title'];
    }

    switch ($name) {
      case 'cardvault_masked_account_number':
        $op = 'LIKE';
        $value = "%" . trim($value, ' %');
        $query->_qill[$grouping][] = $value ? $fieldTitle . " $op '$value'" : $fieldTitle;
        $query->_where[$grouping][] = CRM_Contact_BAO_Query::buildClause($whereField, $op, $value, "String");
        $query->_tables['civicrm_cardvault'] = $query->_whereTables['civicrm_cardvault'] = 1;
        return;
      case 'cardvault_expiry_date_low':
      case 'cardvault_expiry_date_high':
        $query->dateQueryBuilder($values, 'civicrm_cardvault', 'cardvault_expiry_date', 'expiry_date', ts('Card Expiry Date'));
        return;
    }
  }

  public function registerAdvancedSearchPane(&$panes) {
    $panes['Cardvault'] = 'cardvault';
  }

  public function getPanesMapper(&$panes) {
    $panes['Cardvault'] = 'civicrm_cardvault';
  }

  public function setAdvancedSearchPaneTemplatePath(&$paneTemplatePathArray, $type) {
    if ($type  == 'cardvault') {
      $paneTemplatePathArray['cardvault'] = 'CRM/Cardvault/Form/Search/Criteria/Cardvault.tpl';
    }
  }

  public function buildAdvancedSearchPaneForm(&$form, $type) {
    if ($type == 'cardvault') {
      // Not sure if necessary.
      $form->add('hidden', 'hidden_cardvault', 1);

      $form->addElement('text', 'cardvault_masked_account_number', ts('Masked Account Number'));
      CRM_Core_Form_Date::buildDateRange($form, 'cardvault_expiry_date', 1, '_low', '_high', ts('From:'), FALSE, FALSE);
    }
  }

}
