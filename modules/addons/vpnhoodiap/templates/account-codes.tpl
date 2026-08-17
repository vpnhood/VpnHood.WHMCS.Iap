{* VpnHood! IAP — "Your premium codes": the ONLY picker in the whole product
   (lifecycle §8/§9). The app is handed one code or nothing and shows no list;
   someone who owns several codes manages them HERE, next to their invoices.
   Importing consumes nothing — a code keeps working for everyone already using
   it, and any number of accounts may import the same code. *}

<div class="card">
  <div class="card-body">
    <h2 class="card-title">Your premium codes</h2>

    {if $error}
      <div class="alert alert-danger" role="alert">{$error|escape}</div>
    {/if}
    {if $notice}
      <div class="alert alert-success" role="alert">{$notice|escape}</div>
    {/if}

    <p>
      One code serves your account at a time — your signed-in devices pick it up
      automatically. We choose it for you: your first purchase, and when a code runs out,
      the next usable one takes over by itself. Use this page when you want a
      <em>different</em> one, or to add a code someone gave you.
    </p>

    {if $codes}
      <table class="table">
        <thead>
          <tr>
            <th>Code</th>
            <th>Valid until</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {foreach $codes as $code}
            <tr>
              <td><code>{$code.accessCode|escape}</code></td>
              <td>
                {if $code.expiresAt}{$code.expiresAt|substr:0:10|escape}
                {else}<span class="text-muted">starts on first use</span>{/if}
              </td>
              <td class="text-right">
                {if $code.isDefault}
                  <span class="label label-success badge badge-success">serving your account</span>
                {else}
                  <form method="post" action="index.php?m=vpnhoodiap&action=codes" style="margin:0">
                    <input type="hidden" name="do" value="set-default">
                    <input type="hidden" name="token" value="{$csrf|escape}">
                    <input type="hidden" name="serviceId" value="{$code.serviceId|escape}">
                    <button type="submit" class="btn btn-default btn-sm">Use this code</button>
                  </form>
                {/if}
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
      <p class="text-muted">
        Giving a code away? Just send the code itself — nothing on this page moves with it,
        and choosing a different code for yourself takes nothing away from whoever uses that one.
      </p>
    {else}
      <p class="text-muted">No premium codes are attached to this account yet.</p>
    {/if}

    <hr>

    <h3>Add a code</h3>
    <p>
      Were you given a premium code — a gift, or a purchase made under another email
      address? Add it here and it will serve your account. The code is not used up by
      this, and it keeps working for anyone already using it.
    </p>
    <form method="post" action="index.php?m=vpnhoodiap&action=codes" class="form-inline">
      <input type="hidden" name="do" value="import">
      <input type="hidden" name="token" value="{$csrf|escape}">
      <div class="form-group">
        <label class="sr-only" for="vpnhoodiap-import-code">Premium code</label>
        <input type="text" class="form-control" id="vpnhoodiap-import-code" name="accessCode"
               placeholder="Enter your premium code" autocomplete="off" required>
      </div>
      <button type="submit" class="btn btn-primary">Add code</button>
    </form>
  </div>
</div>
