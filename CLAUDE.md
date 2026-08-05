# CLAUDE.md

## What this repo is

The `vpnhoodiap` WHMCS addon: app-store purchases (Google Play / Apple / Microsoft) →
WHMCS client + order + paid invoice → access code delivered by the install's
provisioning module. Ships verbatim inside BOTH the hub package (VpnHood.WHMCS) and
the partner package (VpnHood.WHMCS.Partner). Design doc: `<Vh root>/.user/billing-refactor-plan.md`.

## Non-negotiable rules

- **Provisioning-agnostic.** Never call the access server, the hub API, or any
  `vpnhoodstore`/`vpnhoodpartner` function directly — orders go through localAPI
  (`AddOrder` / `AddInvoicePayment` / `AcceptOrder(autosetup)` / `Module*`) and WHMCS
  invokes the product's own provisioning module. The ONE exception is
  `DeliveryReader`: it may live-fetch an access code via `vpnhoodstore`'s ApiService
  when (and only when) that module exists on the install — guarded by a file-exists
  check, never a hard require.
- **Fail closed.** `api.php` and `webhook.php` answer 404 while the addon is inactive.
  Webhook auth is two-layer: secret path token AND store-native proof (Pub/Sub OIDC /
  Apple JWS). Notification bodies and client proofs are pointers only — entitlement
  comes from re-fetching the purchase from the store API.
- **Capsule writes only to `mod_vpnhood_iap_*` tables.** WHMCS core tables are
  read-only here; orders/invoices/services are only ever touched through localAPI.
- **Idempotency anchors:** unique `(store, purchase_key)` on purchases, unique
  `(store, message_id)` on the event inbox, store order id as the payment transid.
- **Never acknowledge a store purchase before provisioning succeeded** — Google's
  auto-refund of unacknowledged purchases is the fail-safe.
- **Own version stream.** `./VERSION` + `scripts/set-version.sh`; consuming packages
  copy the module verbatim and never restamp it (WHMCS keys `_upgrade()` on it).
- Secrets are stored via WHMCS `EncryptPassword` and are write-only in the admin UI.
  No secrets in this repo, ever.

## Dev / test

- Dev WHMCS + deploy tooling live in the sibling `VpnHood.WHMCS` repo:
  `scripts/deploy-dev.sh iap` deploys THIS repo's tree (env `IAP_REPO` overrides the
  sibling path). SSH key + admin credentials: `<Vh root>/.user/whmcs/`.
- Integration tests follow the hub repo's `tests/integration` style: upload
  `.test.php` over SSH, run with the server's PHP, assert via localAPI reads.
  All writes in tests go through localAPI — never raw INSERT/UPDATE on core tables.
- The dev WHMCS is hub-shaped AND has the partner connector installed, so both
  deployment flavors are testable on the one box.

## Cross-repo contract

- The client apps talk to `api.php` (the "VpnHood Portal API" client lives in the
  VpnHood repo, `VpnHood.AppLib.Portal`). Changing an action's contract requires
  updating that client in the same change set.
- `api.php` **implements** the Portal API contract; it does not own it. The wire
  vocabulary is portal-neutral (session, account, entitlement, plans, access code)
  so a future non-WHMCS backend can implement the same actions without any client
  change. **No WHMCS concept on the wire, ever** — no WHMCS client/invoice/order/
  service ids, no WHMCS error strings; errors are neutral machine codes.
- The hub and partner release pipelines bundle this repo's released files. Layout
  (`modules/…`, `includes/hooks/…`) is part of the contract — moving a file means
  updating both consumers' packaging.
