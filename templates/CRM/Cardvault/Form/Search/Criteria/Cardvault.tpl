<div id="notes-search" class="form-item">
  <table class="form-layout">
    <tr>
      <td>
        {$form.cardvault_masked_account_number.label}<br/>
        {$form.cardvault_masked_account_number.html}
      </td>
    </tr>
    <tr>
       <td colspan="2"><label>{ts}Expiry Date{/ts}</label></td>
    </tr>
    <tr>
       {include file="CRM/Core/DateRange.tpl" fieldName="cardvault_expiry_date" from='_low' to='_high'}
    </tr>
  </table>
</div>
