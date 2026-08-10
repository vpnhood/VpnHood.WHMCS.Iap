{* VpnHood! IAP — "Delete my account", the web deletion path (Play policy: deletion
   must work without the app installed). Same engine as the app's own delete button.
   Wording is store-neutral on purpose — it must fit a buyer from any store. *}

<div class="card">
  <div class="card-body">
    <h2 class="card-title">Delete my account</h2>

    {if $error}
      <div class="alert alert-danger" role="alert">{$error|escape}</div>
    {/if}

    <p>
      This deletes the account for <strong>{$email|escape}</strong>. Before you continue,
      please understand what it means:
    </p>

    <ul>
      <li>Your account is deleted on <strong>all devices</strong> and this cannot be undone
          or restored — signing in again later creates a new, empty account.</li>
      <li>Deleting your account does <strong>not</strong> cancel a subscription. If you have
          one, cancel it in the store where you purchased it — before or after deleting.</li>
      <li>Access you have already paid for keeps working until that period ends.</li>
      <li>Invoices are kept for legal and accounting reasons, with your personal details
          replaced by anonymous placeholders.</li>
    </ul>

    <form method="post" action="index.php?m=vpnhoodiap&action=delete-account">
      <input type="hidden" name="do" value="delete">
      <input type="hidden" name="token" value="{$csrf|escape}">
      <div class="checkbox">
        <label>
          <input type="checkbox" name="confirm" value="yes">
          I understand my account will be permanently deleted everywhere.
        </label>
      </div>
      <button type="submit" class="btn btn-danger">Delete my account permanently</button>
      <a href="clientarea.php" class="btn btn-default">Cancel</a>
    </form>
  </div>
</div>
