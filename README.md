# VpnHood.WHMCS.Iap

WHMCS addon module (`vpnhoodiap`) that turns **app-store purchases** — Google Play now,
Apple App Store and Microsoft Store later — into real WHMCS clients, orders and paid
invoices, and delivers the VpnHood access code through the install's own provisioning
module.

## Why its own repo

The module is **provisioning-agnostic** and ships, verbatim, inside two packages:

- the **hub** WHMCS (VpnHood's own — mapped products use `vpnhoodstore`), and
- **partner** WHMCS installs (white-label partners with their own store apps — mapped
  products use `vpnhoodpartner`, which relays to the hub).

It therefore carries its **own version stream** (`./VERSION`): WHMCS decides whether to
run `_upgrade()` by comparing the version in `vpnhoodiap_config()`, so the same code
must carry the same number on every install. Consuming packages copy the module
verbatim and never restamp it.

## Layout

```
modules/addons/vpnhoodiap/          the addon: admin UI, tables, api.php, webhook.php
modules/gateways/vpnhoodiap.php     bookkeeping gateway (store is the merchant of record)
includes/hooks/vpnhoodiap-suppress-emails.php   aborts WHMCS invoice mail for store-paid invoices
scripts/set-version.sh              propagate ./VERSION into the module
```

Everything extracts at the WHMCS root.

## How it works (short version)

1. The app signs in (`api.php` `auth.token`, Google/Apple id token) — the module
   creates/links a WHMCS client by email (verified emails only).
2. The app buys in the store and posts its proof (`purchase.verify`); the module
   re-validates against the store API, then uses **only WHMCS-native machinery**:
   `AddOrder` on the mapped product → `AddInvoicePayment` (transid = store order id)
   → `AcceptOrder(autosetup)`. WHMCS itself runs whatever provisioning module the
   product uses; the access code is read back from the service and returned.
3. Store webhooks (`webhook.php`) drive renewals/cancellations/refunds via the same
   WHMCS-native paths (pay renewal invoice / ModuleSuspend / ModuleTerminate).

The module never talks to the VpnHood access server or the hub API, and has no code
dependency on `vpnhoodstore` or `vpnhoodpartner` — but a mapped product backed by one
of them must exist, or orders would provision nothing.

## Requirements

- The addon activated (until then `api.php` and `webhook.php` answer 404 — fail closed).
- WHMCS **email verification enabled** (`EnableEmailVerification`) — the module refuses
  to attach purchases to unverified existing emails.
- The bookkeeping gateway `vpnhoodiap` activated but **never shown on the order form**.
- Products for each sellable plan, using `vpnhoodstore` (hub) or `vpnhoodpartner`
  (partner), mapped in the addon's Catalog tab.

## Development / testing

The dev WHMCS and its deploy tooling live in the sibling `VpnHood.WHMCS` repo:

```bash
# from the VpnHood.WHMCS repo — deploys THIS repo's working tree to the dev WHMCS
scripts/deploy-dev.sh iap
```

(`IAP_REPO` overrides the default sibling path.) Integration tests follow the hub
repo's pattern: upload a `.test.php` over SSH and run it against the dev install.
