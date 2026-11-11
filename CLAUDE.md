# CLAUDE.md

## What this repo is

The `vpnhoodiap` WHMCS addon: the app's sign-in and accounts, and app-store purchases
(Google Play / Apple) → WHMCS client + order + paid invoice → access code delivered by
the install's provisioning module. Ships verbatim inside the hub package (VpnHood.WHMCS),
and standalone from its own release, which is how a partner install takes it: the partner
package does not bundle it, because only a white-label partner needs it (for its app's
sign-in, and for in-app purchases if it sells them), and it updates on its own schedule.

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
- **Capsule writes only to `mod_vpnhood_iap_*` tables**, but for four writes no localAPI
  command makes: the bookkeeping gateway's rows in `tblpaymentgateways`, the module's own
  rows in `tbladdonmodules` (its access setting, the updates widget's cache, its version once
  the cron has run a pending upgrade), that gateway
  stamped as the payment method of a store order, its service and its invoice, and the
  account deletion's erasures (`AccountDeletionService::eraseClientTraces`, WHMCS's invoice
  snapshots included). Clients, orders, invoices and services are otherwise only ever
  touched through localAPI. The module also keeps WHMCS's "Store Client Data Snapshot"
  setting on, through WHMCS's own `Setting` (`AccountDeletionService::keepInvoiceSnapshotsOn`):
  a deleted person's invoices keep the buyer only through those snapshots.
- **Idempotency anchors:** unique `(store, purchase_key)` on purchases, unique
  `(store, message_id)` on the event inbox, store order id as the payment transid, unique
  `(store, charge_id)` on the refund ledger with `<order id>-refund` as the reversal's transid.
  Everything that changes a purchase's charge or service, or books its money, holds its
  lifecycle lock (`IapRepository::purchaseLockName`).
- **Never acknowledge a store purchase before provisioning succeeded** — Google's
  auto-refund of unacknowledged purchases is the fail-safe.
- **Own version stream.** `./VERSION` + `scripts/set-version.sh`; consuming packages
  copy the module verbatim and never restamp it (WHMCS keys `_upgrade()` on it).
- **Bump it for ANY change to the module's files** — templates, hooks, a comment — not
  only schema or DB changes. WHMCS decides what to reinstall by comparing the stamped
  version, so an edit shipped under an unchanged number silently never reaches the install.
- **No migrations until a partner install runs the module.** `vpnhoodiap_activate()` creates
  every table in its current shape; a change an existing install needs is made by hand, never
  as an `_upgrade()` step or a one-off cleanup in the module. `_upgrade()` re-runs only
  activation's self-heals, and the cron runs it within one run of an update (hooks.php →
  `vpnhoodiap_runPendingUpgrade`), as an admin opening Addon Modules would. A new table
  reaches an existing install by running activation once by hand, which only adds missing
  tables: in the WHMCS folder, `php -r 'require "init.php"; require
  "modules/addons/vpnhoodiap/vpnhoodiap.php"; print_r(vpnhoodiap_activate());'`. The refund
  ledger (`mod_vpnhood_iap_refunds`) needs this on every install activated before it: until
  then the webhook answers a revocation 503 (the store sends it again), and the cron alerts.
- Secrets are stored via WHMCS `EncryptPassword` and are write-only in the admin UI.
  No secrets in this repo, ever.

## Dev / test

- Dev WHMCS + deploy tooling live in the sibling `VpnHood.WHMCS` repo:
  `scripts/deploy-dev.sh iap` deploys THIS repo's tree (env `IAP_REPO` overrides the
  sibling path).
- This repo is public and never names the dev WHMCS: no host, SSH user, folder or address, in
  code, tests or docs. They live in `<Vh root>/.user/whmcs/dev.env` (SSH login and key, web root,
  site, and the secrets file holding the admin credentials), which `scripts/dev-server.sh` reads; the
  PHP tests find the web root on the box and ask WHMCS for its own address.
- Integration tests follow the hub repo's `tests/integration` style: upload
  `.test.php` over SSH, run with the server's PHP, assert via localAPI reads.
  Writes in tests go through localAPI where a command exists; a fixture none can make is
  written raw, and the test's cleanup removes it.

## Cross-repo contract

- The client apps talk to `api.php` (the "VpnHood Portal API" client lives in the
  VpnHood repo, `VpnHood.AppLib.Portal`). The contract is `modules/addons/vpnhoodiap/
  openapi.json` — served at `GET /openapi.json` — with `docs/PORTAL-API.md` as its
  narrative. Changing an endpoint means changing api.php, openapi.json, PORTAL-API.md
  and that client in the SAME change set.
- `api.php` **implements** the Portal API contract; it does not own it. The wire
  vocabulary is portal-neutral (session, account, entitlement, plans, access code)
  so any other backend could implement the same document without any client
  change. **No WHMCS concept on the wire, ever** — no WHMCS client/invoice/order/
  service ids, no WHMCS error strings; errors are neutral machine codes.
- The hub release pipeline bundles this repo's released files, and a standalone
  install extracts the same zip at the WHMCS root. Layout (`modules/…`,
  `includes/hooks/…`) is part of the contract — moving a file means updating the hub's
  packaging with it.
- The partner-facing store setup guides live in `docs/` (`IAP-GOOGLE-PLAY.md`,
  `IAP-APPLE-APP-STORE.md`, `IAP-MONEY.md`). They are written for a white-label partner
  and are linked from VpnHood.WHMCS.Partner's WHITE-LABEL.md — keep those links working.
