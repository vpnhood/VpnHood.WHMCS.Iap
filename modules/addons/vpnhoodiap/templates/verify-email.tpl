{* VpnHood! IAP — the only client-area page this addon serves.

   Reached when a store purchase attached itself to a WHMCS client that already held
   the address: the subscription is live in the app, but this portal stays shut until
   WHMCS confirms the address belongs to whoever is sitting here. The one action is a
   fresh link, because WHMCS's own expires after 60 minutes. *}

<div class="card">
  <div class="card-body">
    <h2 class="card-title">Confirm your email address</h2>

    {if $attempted}
      {if $resent}
        <div class="alert alert-success" role="alert">
          A new confirmation link is on its way to <strong>{$email|escape}</strong>.
          It is valid for 60 minutes.
        </div>
      {else}
        <div class="alert alert-danger" role="alert">
          We could not send the confirmation email just now. Please try again in a few
          minutes, or contact support if it keeps failing.
        </div>
      {/if}
    {/if}

    <p>
      Your subscription is active and already working in the app — nothing here affects it,
      and there is nothing further to pay.
    </p>
    <p>
      An account for <strong>{$email|escape}</strong> already existed here before your
      purchase, so before opening it we need to confirm the address belongs to you. Click
      the link in the email we sent, and this page will step aside.
    </p>

    <form method="post" action="index.php?m=vpnhoodiap">
      <input type="hidden" name="do" value="resend">
      <button type="submit" class="btn btn-primary">Send me a new link</button>
      <a href="logout.php" class="btn btn-default">Log out</a>
    </form>
  </div>
</div>
