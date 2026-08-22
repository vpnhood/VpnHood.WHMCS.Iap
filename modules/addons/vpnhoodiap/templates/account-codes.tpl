{* VpnHood! IAP — "Your premium codes": the account's inventory page (keyring plan §3, §5, §7).
   The app is handed ONE code or nothing and shows no list; someone who owns several manages them
   HERE, next to their invoices.

   There is no picker any more and no "active" badge: nothing is stored as the selection, so the
   ranking recomputes on every check-in — whatever is being paid for right now first, then the best
   of the rest. The one thing this page decides is whether a code may be chosen at all, and the
   account's ONE upload slot appears as a row with no service behind it. *}

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
      One code serves your account at a time, and your signed-in devices pick it up automatically.
      Whatever you are <strong>paying for right now</strong> is always used first, then your other
      website codes, and last the code you added yourself. If a code stops working your app moves
      to the next one on its own.
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
              <td>
                <code>{$code.accessCode|escape}</code>
                {if $code.uploaded}
                  <br><span class="text-muted small">added by you</span>
                {/if}
                {if !$code.isAutoSelectable}
                  <br><span class="label label-default badge badge-secondary">not chosen automatically</span>
                {/if}
                {if $code.rejected}
                  <br><span class="label label-warning badge badge-warning">refused by the server</span>
                {/if}
              </td>
              <td>
                {if $code.expiresAt}{$code.expiresAt|substr:0:10|escape}
                {else}<span class="text-muted">starts on first use</span>{/if}
              </td>
              <td class="text-right">
                {* Reversible, and it deletes nothing: a device already using the code keeps it
                   until it expires — this only stops the code being handed out again. *}
                {if !$code.uploaded}
                  <form method="post" action="index.php?m=vpnhoodiap&action=codes" style="margin:0">
                    <input type="hidden" name="do" value="auto-select">
                    <input type="hidden" name="token" value="{$csrf|escape}">
                    <input type="hidden" name="serviceId" value="{$code.serviceId|escape}">
                    <input type="hidden" name="isAutoSelectable" value="{if $code.isAutoSelectable}no{else}yes{/if}">
                    <button type="submit" class="btn btn-default btn-sm">
                      {if $code.isAutoSelectable}Keep for later{else}Allow again{/if}
                    </button>
                  </form>
                {/if}
                {* Refused once, and put back by hand: a rejection skips a code, it never deletes
                   one, so the way back is always open. Adding the code again does the same thing. *}
                {if $code.rejected}
                  <form method="post" action="index.php?m=vpnhoodiap&action=codes" style="margin:0">
                    <input type="hidden" name="do" value="retry-import">
                    <input type="hidden" name="token" value="{$csrf|escape}">
                    <input type="hidden" name="accessCode" value="{$code.accessCode|escape}">
                    <button type="submit" class="btn btn-default btn-sm">Allow again</button>
                  </form>
                {/if}
                {if $code.uploaded}
                  <form method="post" action="index.php?m=vpnhoodiap&action=codes"
                        style="margin:6px 0 0">
                    <input type="hidden" name="do" value="remove-import">
                    <input type="hidden" name="token" value="{$csrf|escape}">
                    <div class="checkbox small" style="margin:0 0 4px">
                      <label>
                        <input type="checkbox" name="confirm" value="yes">
                        Remove this access code from your account and signed-in devices? The code
                        itself keeps working for anyone who still has it, and you can add it again.
                      </label>
                    </div>
                    <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                  </form>
                {/if}
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
      <p class="text-muted">
        Bought a code to give away? Use <em>Keep for later</em> so it is not picked for your own
        devices, then send the code itself — nothing on this page moves with it.
      </p>
    {else}
      <p class="text-muted">No premium codes are attached to this account yet.</p>
    {/if}

    <hr>

    <h3>Add a code</h3>
    <p>
      Were you given a premium code — a gift, or a purchase made under another email
      address? Add it here and your devices can use it. Your account keeps <strong>one</strong>
      added code: adding a different one replaces the code you saved before (the old code
      itself keeps working for anyone using it).
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
