(function($, _, ts){

  $('.crm-button-type-cardvault-charge').click(function(event) {
    var contribution_id = $(this).data('cardvault-contribution-id');

    if ($(this).data('cardvault-processing')) {
      CRM.alert("Processing in progress, please do not double-click. If the process is stuck, there may be more information by reloading the page and looking at the latest activities for this contact");
      return;
    }

    $(this).data('cardvault-processing', 1);

    $('i', this)
      .removeClass('fa-credit-card')
      .addClass('fa-gear')
      .addClass('fa-spin');

    if (!contribution_id) {
      CRM.alert("Contribution ID not found, this seems like a bug");
    }

    CRM.api3('Cardvault', 'charge', {
      'contribution_id': contribution_id
    }).done(function(result) {
      console.log(result);

      if (result.is_error) {
        CRM.alert(result.error_message, CRM.ts('Error'), 'error');
      }
      else {
        CRM.alert("Transaction processed: " + result['values']['trxn_id'] + ". You can close the popup and reload the page for more visual confirmations.", '', 'success');
      }
    });

    event.preventDefault();
  });

})(CRM.$, CRM._, CRM.ts('cardvault'));
