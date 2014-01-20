<?php

require_once 'CRM/Core/Page.php';
require_once 'packages/php-iban-1.4.0/php-iban.php';

class CRM_Sepa_Page_CreateMandate extends CRM_Core_Page {

  function run() {
    // print_r("<pre>");
    // print_r($_REQUEST);
    // print_r("</pre>");
    if (isset($_REQUEST['mandate_type'])) {
      $contact_id = $_REQUEST['contact_id'];
      $this->assign("back_url", CRM_Utils_System::url('civicrm/contact/view', "reset=1&cid=${contact_id}&selectedChild=contribute"));

      $errors = $this->validateParameters($_REQUEST['mandate_type']);
      if (count($errors) > 0) {
        // i.e. validation failed
        $this->assign('validation_errors', $errors);
        $_REQUEST['cid'] = $contact_id;
        $this->prepareCreateForm();
      } else {
        // validation o.k. = > create
        if ($_REQUEST['mandate_type']=='OOFF') {
          $this->createMandate('OOFF');
        } else if ($_REQUEST['mandate_type']=='RCUR') {
          $this->createMandate('RCUR');
        }
      }

    } else if (isset($_REQUEST['cid'])) {
      $this->prepareCreateForm();

    }
    parent::run();
  }



  /**
   * Creates a SEPA mandate for the given type
   */
  function createMandate($type) {
    // first create a contribution
    $payment_instrument_id = CRM_Core_OptionGroup::getValue('payment_instrument', $type, 'name');
    $contribution_status_id = CRM_Core_OptionGroup::getValue('contribution_status', 'Pending', 'name');

    $contribution_data = array(
        'version'                   => 3,
        'contact_id'                => $_REQUEST['contact_id'],
        'campaign_id'               => $_REQUEST['campaign_id'],
        'financial_type_id'         => $_REQUEST['financial_type_id'],
        'payment_instrument_id'     => $payment_instrument_id,
        'contribution_status_id'    => $contribution_status_id,
        'receive_date'              => $_REQUEST['date'],
        'currency'                  => 'EUR',
        'source'                    => $_REQUEST['source'],
      );

    if ($type=='OOFF') {
      $initial_status = 'OOFF';
      $entity_table = 'civicrm_contribution';
      $contribution_data['total_amount']        = number_format($_REQUEST['total_amount'], 2, '.', '');
      $contribution = civicrm_api('Contribution', 'create', $contribution_data);
    } else if ($type=='RCUR') {
      $initial_status = 'FRST';
      $entity_table = 'civicrm_contribution_recur';
      $contribution_data['amount']              = number_format($_REQUEST['total_amount'], 2, '.', '');
      $contribution_data['start_date']          = $_REQUEST['start_date'];
      $contribution_data['end_date']            = $_REQUEST['end_date'];
      $contribution_data['create_date']         = date('YmdHis');
      $contribution_data['modified_date']       = date('YmdHis');
      $contribution_data['frequency_unit']      = 'month';
      $contribution_data['frequency_interval']  = $_REQUEST['interval'];
      $contribution_data['cycle_day']           = $_REQUEST['cycle_day'];
      $contribution_data['is_email_receipt']    = 0;
      $contribution = civicrm_api('ContributionRecur', 'create', $contribution_data);
    }

    if ($contribution['is_error']) {
      CRM_Core_Session::setStatus(sprintf(ts("Couldn't create contribution for contact #%s"), $cid), ts('Error'), 'error');
      $this->assign("error_title", ts("Couldn't create contribution"));
      $this->assign("error_message", ts($contribution['error_message']));
      return;
    }

    // create a note, if requested
    if ($_REQUEST['note']) {
      // add note
      $create_note = array(
        'version'                   => 3,
        'entity_table'              => $entity_table,
        'entity_id'                 => $contribution['id'],
        'note'                      => $_REQUEST['note'],
        'privacy'                   => 0,
      );

      $create_note_result = civicrm_api('Note', 'create', $create_note);
      if ($create_note_result['is_error']) {
        // don't consider this a fatal error...
        CRM_Core_Session::setStatus(sprintf(ts("Couldn't create note for contribution #%s"), $contribution['id']), ts('Error'), 'alert');
        error_log("org.project60.sepa_dd: error creating note - ".$create_note_result['error_message']);
      }
    }

    // next, create mandate
    $mandate_data = array(
        'version'                   => 3,
        'debug'                     => 1,
        'reference'                 => "WILL BE SET BY HOOK",
        'contact_id'                => $_REQUEST['contact_id'],
        'entity_table'              => $entity_table,
        'entity_id'                 => $contribution['id'],
        'creation_date'             => date('YmdHis'),
        'validation_date'           => date('YmdHis'),
        'date'                      => $_REQUEST['date'],
        'iban'                      => $_REQUEST['iban'],
        'bic'                       => $_REQUEST['bic'],
        'status'                    => $initial_status,
        'type'                      => $type,
        'creditor_id'               => $_REQUEST['creditor_id'],
        'is_enabled'                => 1,
      );
    // call the hook for mandate generation
    // TODO: Hook not working: CRM_Utils_SepaCustomisationHooks::create_mandate($mandate_data);
    sepa_civicrm_create_mandate($mandate_data);

    $mandate = civicrm_api('SepaMandate', 'create', $mandate_data);
    if ($mandate['is_error']) {
      CRM_Core_Session::setStatus(sprintf(ts("Couldn't create %s mandate for contact #%s"), $type, $cid), ts('Error'), 'error');
      $this->assign("error_title", ts("Couldn't create mandate"));
      $this->assign("error_message", ts($mandate['error_message']));
      return;
    }

    $this->assign("reference", $mandate_data['reference']);
  }


  /**
   * Will prepare the form and look up all necessary data
   */
  function prepareCreateForm() {
    // load financial types
    $this->assign("financial_types", CRM_Contribute_PseudoConstant::financialType());
    $this->assign("today", date('Y-m-d'));

    // first, try to load contact
    $contact_id = $_REQUEST['cid'];
    $contact = civicrm_api('Contact', 'getsingle', array('version' => 3, 'id' => $contact_id));
    if ($contact['is_error']) {
      CRM_Core_Session::setStatus(sprintf(ts("Couldn't find contact #%s"), $cid), ts('Error'), 'error');
      $this->assign("display_name", "ERROR");
      return;
    }

    $this->assign("contact_id", $contact_id);
    $this->assign("display_name", $contact['display_name']);

    // look up campaigns
    $campaign_query = civicrm_api('Campaign', 'get', array('version'=>3, 'is_active'=>1));
    $campaigns = array();
    if (!$campaign_query['is_error']) {
      foreach ($campaign_query['values'] as $campaign_id => $campaign) {
        $campaigns[$campaign_id] = $campaign['name'];
      }
    }
    $this->assign('campaigns', $campaigns);

    // look up account in other SEPA mandates
    $known_accounts = array();
    $query_sql = "SELECT DISTINCT iban, bic FROM civicrm_sdd_mandate WHERE contact_id=$contact_id;";
    $old_mandates = CRM_Core_DAO::executeQuery($query_sql);
    while ($old_mandates->fetch()) {
      $value = $old_mandates->iban.'/'.$old_mandates->bic;
      array_push($known_accounts, 
        array("name" => $old_mandates->iban, "value"=>$value));
    }


    // look up account in CiviBanking (if enabled...)
    $iban_reference_type = CRM_Core_OptionGroup::getValue('civicrm_banking.reference_types', 'IBAN', 'value', 'String', 'id');
    if ($iban_reference_type) {
      $accounts = civicrm_api('BankingAccount', 'get', array('version' => 3, 'contact_id' => $contact_id));
      if (!$accounts['is_error']) {
        foreach ($accounts['values'] as $account_id => $account) {
          $account_ref = civicrm_api('BankingAccountReference', 'getsingle', array('version' => 3, 'ba_id' => $account_id, 'reference_type_id' => $this->IBAN_REFERENCE_TYPE));
          if (!isset($account_ref['is_error'])) {
            $account_data = json_decode($account['data_parsed']);
            if (isset($account_data->BIC)) {
              // we have IBAN and BIC -> add:
              $value = $account_ref['reference'].'/'.$account_data->BIC;
              array_push($known_accounts, 
                array("name" => $account_ref['reference'], "value"=>$value));
            }
          }
        }
      }
    }

    // add default entry
    array_push($known_accounts, array("name" => ts("enter new account"), "value"=>"/"));
    $this->assign("known_accounts", $known_accounts);

    // look up creditors
    $creditor_query = civicrm_api('SepaCreditor', 'get', array('version' => 3));
    $creditors = array();
    if (!$creditor_query['is_error']) {
      foreach ($creditor_query['values'] as $creditor_id => $creditor) {
        $creditors[$creditor_id] = $creditor['name'];
      }
    }
    $this->assign('creditors', $creditors);
    
    // all seems to be ok.
    $this->assign("submit_url", CRM_Utils_System::url('civicrm/sepa/cmandate'));
  }


  /**
   * Will checks all the POSTed data with respect to creating a mandate
   *
   * @return array('<field_id>' => '<error message>') with the fields that have not passed
   */
  function validateParameters() {
    $errors = array();

    // check amount
    if (!isset($_REQUEST['total_amount'])) {
      $errors['total_amount'] = sprintf(ts("'%s' is a required field."), ts("Amount"));
    } else {
      $_REQUEST['total_amount'] = str_replace(',', '.', $_REQUEST['total_amount']);
      if (strlen($_REQUEST['total_amount']) == 0) {
        $errors['total_amount'] = sprintf(ts("'%s' is a required field."), ts("Amount"));
      } elseif (!is_numeric($_REQUEST['total_amount'])) {
        $errors['total_amount'] = ts("Cannot parse amount");
      } elseif ($_REQUEST['total_amount'] <= 0) {
        $errors['total_amount'] = ts("Amount has to be positive");
      }
    }

    // check BIC
    if (!isset($_REQUEST['bic'])) {
      $errors['bic'] = sprintf(ts("'%s' is a required field."), "BIC");
    } else {
      if (strlen($_REQUEST['bic']) == 0) {
        $errors['bic'] = sprintf(ts("'%s' is a required field."), "BIC");
      } elseif (strlen($_REQUEST['bic']) < 8) {
        $errors['bic'] = ts("BIC too short");
      }
    }

    // check IBAN
    if (!isset($_REQUEST['iban'])) {
      $errors['iban'] = sprintf(ts("'%s' is a required field."), "IBAN");
    } else {
      if (strlen($_REQUEST['iban']) == 0) {
        $errors['iban'] = sprintf(ts("'%s' is a required field."), "IBAN");
      } else {
        if (!verify_iban($_REQUEST['iban'])) {
          $errors['iban'] = ts("IBAN is not correct");
        }
      }
    }

    // check date fields
    if ($_REQUEST['mandate_type']=='OOFF') {
      if (!$this->_check_date('date'))
        $errors['date'] = ts("Incorrect date format");
    } elseif ($_REQUEST['mandate_type']=='RCUR') {
      if (!$this->_check_date('start_date'))
        $errors['start_date'] = ts("Incorrect date format");
      if (isset($_REQUEST['end_date']) && strlen($_REQUEST['end_date'])) {
        if (!$this->_check_date('end_date'))
          $errors['end_date'] = ts("Incorrect date format");        
      }
    }

    return $errors;
  }

  function _check_date($date_field) {
    if (!isset($_REQUEST[$date_field])) {
      return false;
    } else {
      $parsed_date = date_parse_from_format('Y-m-d', $_REQUEST[$date_field]);
      if ($parsed_date['errors']) {
        return false;
      } else {
        return true;
      }
    }
  }
}