# VpnHood.WHMCS.Iap

**WHMCS addon module (`vpnhoodiap`) that turns app-store purchases into real WHMCS
orders — and delivers VpnHood access codes through the install's own provisioning
module.**

Supported stores: **Google Play** and **Apple App Store** (implemented); **Microsoft
Store** (planned). The store remains the merchant of record; WHMCS becomes the single
system of record for customers, orders, invoices, renewals and refunds.

## How it works

```mermaid
sequenceDiagram
    participant App as VpnHood app
    participant Store as App store
    participant Iap as vpnhoodiap (this module)
    participant WHMCS as WHMCS core
    participant Prov as Provisioning module

    App->>Iap: POST /auth/sessions (Google/Apple id token, or email + password)
    App->>Store: buy subscription
    App->>Iap: POST /billing/purchases {store, proof}
    Iap->>Store: re-validate against the store API
    Iap->>WHMCS: AddOrder → AddInvoicePayment → AcceptOrder(autosetup)
    WHMCS->>Prov: provision (vpnhoodstore / vpnhoodpartner)
    Iap-->>App: {entitlement, accessCode} — one call, no polling
    Store-->>Iap: webhooks: renew / cancel / refund / expire
```

1. **Sign-in** — the app posts a Google/Apple id token — or the WHMCS client-area
   email + password, with WHMCS two-factor auth honoured — to `POST /auth/sessions`; the
   module creates or links a WHMCS client by email (verified emails only) and returns an
   opaque session token.
2. **Purchase** — the app buys in the store and posts its proof (`POST /billing/purchases`); the
   module re-validates against the store API, then uses **only WHMCS-native machinery**:
   `AddOrder` on the mapped product → `AddInvoicePayment` (transid = store order id) →
   `AcceptOrder(autosetup)`. WHMCS itself runs whatever provisioning module the product
   uses; the access code is read back from the service and returned synchronously.
3. **Lifecycle** — store webhooks (`webhook.php`) drive renewals, cancellations and
   refunds through the same WHMCS-native paths (pay renewal invoice / `ModuleSuspend` /
   `ModuleTerminate`).
4. **Client area** — a codes page in the WHMCS client area lets signed-in customers
   view and manage the access codes their account holds.

## Accounts & access codes

An account **holds** codes rather than owning one: a store subscription's code, codes
bought on the portal's own website, and the one code the person typed and uploaded all
live in a keyring. The app is always told a single code — `GET /v1/account` recomputes
the best candidate on every read (a paid-now store subscription first, then live web
billing, then the uploaded code, then the rest) — while the full list lives in the
client-area codes page, with reversible *keep for later* / *allow again* controls. A
device whose code the access server refuses reports one bit back and is served the next
candidate; the system never deletes a code.

Account deletion (`DELETE /v1/account`, or the client-area page so it works without the
app installed) anonymizes the customer, cancels web billing at the end of its paid
period, and **never touches a store subscription** — Restore Purchases from a new
account re-attaches it. The full contract and reasoning live in
[docs/PORTAL-API.md](docs/PORTAL-API.md).

## Provisioning-agnostic by design

The module ships **verbatim** inside two packages:

| Package | Mapped products use | Delivery |
| --- | --- | --- |
| **Hub** ([VpnHood.WHMCS]) — VpnHood's own WHMCS | `vpnhoodstore` | access code fetched live from the access manager |
| **Partner** ([VpnHood.WHMCS.Partner]) — white-label partners with their own store apps | `vpnhoodpartner` | access code relayed from the hub, stored on the service |

The module never talks to the VpnHood access server or the hub API, and has no code
dependency on `vpnhoodstore` or `vpnhoodpartner` — but a mapped product backed by one of
them must exist, or orders would provision nothing.

Because the same code runs on every install, the repo carries its **own version stream**
(`./VERSION`): WHMCS decides whether to run `_upgrade()` by comparing the version in
`vpnhoodiap_config()`, so the same code must carry the same number everywhere. Consuming
packages copy the module verbatim and never restamp it.

## Security posture

- **Fail closed** — `api.php` and `webhook.php` answer 404 while the addon is inactive.
- **Two-layer webhook auth** — a secret path token *and* the store-native proof
  (Google Pub/Sub OIDC JWT / Apple JWS); notification bodies are treated as pointers
  only — entitlement always comes from re-fetching the purchase from the store API.
- **Never acknowledge before provisioning succeeds** — Google auto-refunds
  unacknowledged purchases, which is the customer's fail-safe.
- **Store credentials** are stored encrypted (WHMCS `EncryptPassword`) and are
  write-only in the admin UI. Purchase tokens are never returned to clients.
- The module writes only to its own `mod_vpnhood_iap_*` tables; WHMCS core data is
  touched exclusively through `localAPI`.

## Layout

```text
modules/addons/vpnhoodiap/          the addon: admin UI, tables, api.php, webhook.php
modules/addons/vpnhoodiap/lib/      Auth (Google/Apple id tokens), Stores (GooglePlay /
                                    AppStore adapters), Provisioning (orders, accounts,
                                    keyring, deletion), Controllers (api.php routing)
modules/addons/vpnhoodiap/openapi.json  the Portal API contract, served at /openapi.json
docs/PORTAL-API.md                  the Portal API, explained
docs/IAP-GOOGLE-PLAY.md             store setup: Google Play (app owner's own accounts)
docs/IAP-APPLE-APP-STORE.md         store setup: Apple App Store
docs/IAP-MONEY.md                   how store sales appear in WHMCS billing
modules/gateways/vpnhoodiappay.php     bookkeeping gateway (store is the merchant of record)
includes/hooks/                     WHMCS-level hooks: cron, gateway hiding, product
                                    actions, refund marks, mail suppression (store
                                    invoices + deleted clients), verification gate
scripts/set-version.sh              propagate ./VERSION into the module
scripts/test-dev.sh                 run the test suites against the dev WHMCS
scripts/watch-dev.sh                live tail of the module pipeline on the dev WHMCS
tests/unit/                         dependency-free unit tests (pure PHP, no WHMCS)
tests/integration/                  *.test.php run inside the dev WHMCS over SSH
```

Everything extracts at the WHMCS root.

## Requirements

- WHMCS 8.x / 9.x, PHP 8.1+.
- The addon activated (until then the public endpoints answer 404).
- WHMCS **email verification enabled** (`EnableEmailVerification`) — the module refuses
  to attach purchases to unverified existing emails.
- The bookkeeping gateway `vpnhoodiappay` activated but **never shown on the order form**.
- Products for each sellable plan, using `vpnhoodstore` (hub) or `vpnhoodpartner`
  (partner), mapped in the addon's **Catalog** tab.
- **Every app package must be registered** in the addon's **Apps** tab, or sign-in answers
  `unknown_app` — including builds that sell nothing. A direct-download build (own
  website, sideloaded APK) is registered under store `web`: no credentials, no adapter,
  no webhook; it only lets the package sign in.
- Per store app, in the addon's **Apps** tab: the package/bundle id, the OAuth client
  ids sign-in tokens may be issued to, and the store credentials —
  **Google Play**: the Play service-account JSON, plus the Pub/Sub push service account
  whose OIDC token authenticates RTDN webhooks;
  **App Store**: the App Store Server API key as `{issuerId, keyId, privateKey}` (the
  In-App Purchase `.p8`) — ASSN V2 notifications authenticate themselves through their
  JWS x5c chain, pinned to Apple Root CA-G3.
- **Store webhooks wired up** (see below) — without them, renewals, cancellations and
  refunds never reach WHMCS, so entitlements drift after the first purchase.

## Wiring the store webhooks

Each app row on the **Apps** tab shows its own **Webhook URL**
(`webhook.php?store=…&t=<secret>`). The token in it is the first auth layer; the second
is store-native proof, so a leaked URL alone buys nothing — but treat it as a secret
anyway, and re-create the app row if it leaks.

**Google Play (RTDN via Pub/Sub).** Play cannot call a URL directly; it publishes to a
Pub/Sub topic and a push subscription delivers to you:

1. In Google Cloud (the same project as the Play service account), create a topic, e.g.
   `play-rtdn`.
2. In **Play Console → your app → Monetize → Monetization setup → Real-time developer
   notifications**, set that topic.
3. Create a **push subscription** on the topic with the app row's Webhook URL as the
   endpoint, and **enable authentication (OIDC)** with a service account of your choice.
4. Enter that service account's email in the app row's **Pub/Sub Push Service Account**
   field — the module verifies each push's OIDC token against it.
5. Send a test notification from Play Console; it must appear in the addon's **Events**
   tab.

**App Store (App Store Server Notifications V2).** Apple calls the URL directly:

1. In **App Store Connect → your app → General → App Information → App Store Server
   Notifications**, paste the app row's Webhook URL as the **Production Server URL**.
   Current App Store Connect configures **Version 2** notifications — there is no version
   choice any more (V1 is deprecated); if an older UI still offers one, pick Version 2.
2. Point the **Sandbox Server URL** at your *staging/dev* install's webhook URL —
   sandbox and TestFlight purchases should exercise that install, not production. Leaving
   it empty sends BOTH environments' notifications to the Production URL.
3. No extra auth field: ASSN payloads are signed JWS whose x5c chain the module pins to
   Apple Root CA-G3.

**Microsoft Store** has no webhook at all; when implemented, entitlement drift is covered
by client push plus daily re-validation instead.

## Development & testing

The dev WHMCS and its deploy tooling live in the sibling [VpnHood.WHMCS] repo:

```bash
# from the VpnHood.WHMCS repo — deploys THIS repo's working tree to the dev WHMCS
scripts/deploy-dev.sh iap

# from THIS repo — run unit + integration suites on the dev WHMCS
scripts/test-dev.sh all
```

(`IAP_REPO` overrides the default sibling path.) Unit tests are pure PHP with no WHMCS
or Composer dependency; integration tests are uploaded over SSH and run inside the real
dev install, asserting through `localAPI` reads.

## Related repositories

| Repo | Role |
| --- | --- |
| [VpnHood] | The VPN client/server and app libraries (the "Portal API" client lives here) |
| [VpnHood.WHMCS] | Hub WHMCS modules: `vpnhoodstore` provisioning, partner hub |
| [VpnHood.WHMCS.Partner] | Partner connector module (`vpnhoodpartner`) |

## License

[LGPL-2.1](LICENSE) — same license as the main [VpnHood] project.

[VpnHood]: https://github.com/vpnhood/VpnHood
[VpnHood.WHMCS]: https://github.com/vpnhood/VpnHood.WHMCS
[VpnHood.WHMCS.Partner]: https://github.com/vpnhood/VpnHood.WHMCS.Partner
