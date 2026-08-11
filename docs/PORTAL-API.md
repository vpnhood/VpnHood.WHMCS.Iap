# The Portal API

The REST API a VpnHood app uses to sign in, redeem an app-store purchase, and receive
its access code.

The machine-readable contract is [`openapi.json`](../modules/addons/vpnhoodiap/openapi.json),
which ships inside the module and is served by every install at
`GET /openapi.json` — point Swagger UI or a client generator straight at it. This
page is the narrative version: what the endpoints are for, and why they behave as they do.

```text
Base URL   https://<whmcs>/modules/addons/vpnhoodiap/api.php
Resource   /<controller>/<resource>    (the path after api.php — PHP PATH_INFO)
Auth       Authorization: Bearer <session token>
Success    the resource itself, as JSON — no envelope
Failure    RFC 9457 application/problem+json, with a stable `code`
```

The vocabulary is deliberately backend-neutral — sessions, accounts, plans, purchases,
entitlements, access codes. **No WHMCS concept ever appears on the wire**: no client,
order, invoice or service id, and no WHMCS error text. A different backend can implement
this same document and the apps will not know the difference.

## Endpoints

| | Endpoint | Auth | Purpose |
| --- | --- | :---: | --- |
| `GET` | `/openapi.json` | — | This API's OpenAPI 3.1 document |
| `GET` | `/system/status` | — | Is the portal installed, active and healthy |
| `POST` | `/auth/sessions` | — | Sign in with a Google/Apple id token → session token |
| `DELETE` | `/auth/sessions/current` | ✔ | Sign out (revokes the token server-side) |
| `GET` | `/account` | ✔ | The signed-in account |
| `DELETE` | `/account` | ✔ | Delete the account everywhere ("forget me") |
| `GET` | `/account/entitlements` | ✔ | What that account currently holds |
| `GET` | `/billing/plans?store=&packageName=` | — | Plans this app may sell in that store |
| `POST` | `/billing/purchases` | ✔ | Redeem a store purchase → access code |

A path that exists but is called with the wrong method answers **405** with an `Allow`
header, never 404 — so an integrator can tell a wrong URL from a wrong verb.

Every resource hangs off a controller. `/openapi.json` is the deliberate exception:
tooling expects an API's document at its root, so that is where it lives.

### No version in the path

There is no `/v1`, on purpose. A version segment only pays for itself when a *whole*
API is redesigned and served in parallel; here the unit of change is the endpoint. A
breaking change ships as a **new endpoint beside the old one**, so an app already
published to a store — which can never be force-updated — keeps working, and the other
seven endpoints are not dragged into the migration.

That puts one obligation on this API: changes must stay additive. New fields may appear
in any response at any time, and clients must ignore the ones they don't know (the
official client does). The contract version is reported by `GET /system/status` and in
`openapi.json`, not in the URL.

## Authentication

`POST /auth/sessions` exchanges an identity provider's id token for a **portal session
token**: 64 hex characters, valid 30 days, stored only as a SHA-256 hash, revocable at
any time. It is deliberately *not* a JWT — there are no signing keys to manage and a
sign-out is real, not just a client-side forget.

Send it on every other call:

```http
Authorization: Bearer 0f1e2d3c4b5a…
```

`GET /billing/plans` is the one resource outside `/auth` and `/system` that takes no
session. An app has to render its plans page before anyone signs in, and gating it would
force every app to ship a hardcoded product list — the exact drift this catalog exists to
prevent. It answers only **what** an app sells, never who buys it, and those product ids
are already public in the store listing.

Some proxies strip `Authorization`; the same token is also accepted as
`X-Portal-Token: <token>`. The official client sends both.

**Why the id token is not enough.** It is verified against the provider's published
keys, and its audience must be one of the OAuth client ids registered for that app — a
token minted for someone else's app is refused. The provider must also state that the
email is verified: accounts are matched by email, so an unproven mailbox is refused
outright.

## The purchase flow

```text
1. POST /auth/sessions       → { accessToken, userId, … }
2. buy in the store, passing userId as
   obfuscatedAccountId (Google) / appAccountToken (Apple)
3. POST /billing/purchases   → { state: "provisioned", accessCode, expiresAt, planId, store, … }
4. redeem accessCode in the client — premium is on
```

One synchronous call, no polling. Everything after that — renewals, cancellations,
refunds — arrives as a store webhook, so `GET /account/entitlements` is always the
current truth; the app does not have to track subscription state itself.

An entitlement also describes the subscription it came from — `purchasedAt`,
`autoRenewing`, `priceAmount` + `priceCurrency`, and `billingPeriod` as an ISO-8601
duration (`P1M`, `P1Y`, …). Both endpoints return them, so an app can render a
subscription summary from the entitlement alone and never has to ask the store a
second time for what it already paid. The price is the **store's** figure for the
current period, not a portal catalogue price: the two differ whenever the store
rounds to its own local price points, and what the buyer was actually charged is
the one worth showing.

Three properties worth knowing before integrating:

- **The proof is a pointer, not evidence.** Whatever the store handed the app is only
  used to look the purchase up: the portal re-fetches it from the store's own API and
  acts on *that*. A forged body buys nothing.
- **The buyer must be the signed-in user.** The purchase's store attribution
  (`obfuscatedAccountId` / `appAccountToken`) must equal the session's `userId`, or the
  call fails with `purchase_account_mismatch`. A stolen purchase token cannot be
  redeemed into another account.
- **Redeeming twice is safe.** The store purchase key is the idempotency anchor: a
  retry returns the same entitlement and never creates a second order. Retry freely
  after a network failure.

### 201 versus 202

`POST /billing/purchases` answers **201** when the entitlement is delivered
(`accessCode` present) and **202** when it is not deliverable *yet*:

| `state` | Meaning | What the client should do |
| --- | --- | --- |
| `provisioned` | Delivered | Use `accessCode` |
| `pending` | The store has not settled the payment (deferred/slow payment methods) | Retry shortly |

A purchase is never held up for a portal-side email confirmation. The identity provider
has already proved the mailbox — sign-in is refused otherwise — so asking the customer
to confirm the same address again before delivering what they just paid for buys
nothing. Where a purchase attaches to a customer account that existed *before* it, the
portal closes that account's **web area** until it confirms the address, which keeps
someone who pre-registered another person's address from reading their account. That is
a portal-side concern only: this API neither reports it nor gates on it, and the
subscription works in the app throughout.

**One live subscription per account.** A second purchase arriving while another is still
active is refused with `409` instead of provisioned, and deliberately **not acknowledged
to the store** — Google auto-refunds an unacknowledged subscription after a few days, so
the customer is made whole rather than paying twice for one entitlement. An upgrade or
resubscribe is not a second subscription: it carries the purchase it replaces and is
provisioned normally. The same unacknowledged fail-safe covers every provisioning
failure.

## Errors

```json
{
  "type": "https://docs.vpnhood.com/portal-api/errors/unauthorized",
  "title": "Unauthorized",
  "status": 401,
  "code": "unauthorized",
  "detail": "Unauthorized."
}
```

**Branch on `code`.** `detail` is prose for logs and support and may be reworded;
`code` is the contract.

| Code | Status | Meaning |
| --- | :---: | --- |
| `bad_request` | 400 | Malformed body or a missing required field |
| `unauthorized` | 401 | No session token, or it is expired or revoked |
| `invalid_id_token` | 401 | The id token is malformed, expired, or not issued to this app |
| `unknown_app` | 403 | The package name is not registered on this portal |
| `provider_email_unverified` | 403 | Google/Apple has not verified the signed-in email |
| `purchase_account_mismatch` | 403 | The purchase belongs to a different account |
| `unsupported_provider` | 400 | Sign-in provider is not `google` or `apple` |
| `unknown_store` | 400 | Store is not one this API knows |
| `purchase_invalid` | 400 | The store would not confirm the proof |
| `not_found` | 404 | No such resource — **or the addon is not active on this install** |
| `method_not_allowed` | 405 | Wrong verb for an existing resource (see `Allow`) |
| `purchase_unattributed` | 409 | No attribution the portal can resolve to an account; recorded for an admin |
| `purchase_inactive` | 410 | Expired, cancelled or refunded at the store |
| `plan_not_available` | 422 | The store product is not mapped to a plan here; parked, admin alerted |
| `deletion_blocked` | 409 | The account still has active web services; cancel them in the web client area first |
| `rate_limited` | 429 | Too many requests from this address |
| `store_not_supported` | 501 | Known store, not implemented on this portal yet |
| `provisioning_failed` | 502 | Downstream provisioning failed; nothing half-created, safe to retry |
| `deletion_failed` | 502 | Anonymization or invoice cleanup failed; nothing partial, safe to retry |
| `purchase_in_progress` | 503 | Another request is redeeming this same purchase; retry shortly |
| `internal_error` | 500 | Unexpected; recorded in the module log |

Unrecognised codes should be treated as a generic failure of their status class — new
ones may be added.

**Rate limits** (sliding window, per IP): `POST /auth/sessions` 20 per 5 min,
`POST /billing/purchases` 30 per 5 min, `GET /system/status` 30 per min,
`DELETE /account` 5 per 5 min.

## Account deletion

`DELETE /account` is the "forget me" the app stores and GDPR require: sessions on
every device, sign-in identities and the account row are erased in one call, and the
customer record behind the retained invoices is anonymized and closed. Signing in
again later creates a brand-new empty account — there is no restore, and no
identifier of the deleted person is kept.

What deletion deliberately does **not** do: it never cancels a store subscription
(the customer cancels in the store where they purchased — before or after deleting),
and it never terminates a running service — an access code is an open gate with no
personal data, and the paid period keeps working until the store's own lifecycle ends
it. Paid invoices are retained under legal duty with the personal details replaced by
placeholders; unpaid ones are cancelled so nothing can ever bill the deleted person.

A person who also has active WEB services (sold on the portal's own site) is refused
with `deletion_blocked` until those are cancelled in the web client area — this API
never touches a payment gateway's recurring agreement. The same deletion is available
on the web at `index.php?m=vpnhoodiap&action=delete-account`, so it works without the
app installed (Play policy).

**Fail closed.** While the addon is deactivated, *every* endpoint answers 404. A client
that gets 404 from `/system/status` is talking to a WHMCS with no portal configured —
not a portal that is merely busy.

## Try it

```bash
PORTAL=https://whmcs.example.com/modules/addons/vpnhoodiap/api.php

# is it alive?
curl -s $PORTAL/system/status

# sign in (id token from the app's Google/Apple sign-in)
TOKEN=$(curl -s -X POST $PORTAL/auth/sessions \
  -H 'Content-Type: application/json' \
  -d '{"provider":"google","idToken":"'"$ID_TOKEN"'","packageName":"com.vpnhood.connect.android"}' \
  | jq -r .accessToken)

# what does this account hold?
curl -s $PORTAL/account/entitlements -H "Authorization: Bearer $TOKEN"

# redeem a purchase
curl -s -X POST $PORTAL/billing/purchases \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"store":"googleplay","packageName":"com.vpnhood.connect.android",
       "proof":{"purchaseToken":"'"$PURCHASE_TOKEN"'"}}'
```

To browse the API instead, open any Swagger UI against
`https://whmcs.example.com/modules/addons/vpnhoodiap/api.php/openapi.json`.

## The client

The reference implementation is **`VpnHood.AppLib.Portal`** in the [VpnHood] repo —
`PortalApiClient` (the typed stub; problem+json surfaces as the toolkit's
standard `ApiException`, machine code in `Data["Code"]`),
`PortalAuthenticationProvider` (sessions), `PortalAccountProvider` (account and
entitlements, and the owner of both the catalog and the order processor) and
`PortalOrderProcessor` (purchases). The catalog side is why `/billing/plans` matters to
the client: neither StoreKit nor Play Billing can list an app's own products, so the app
asks the portal which ids to price. Changing an endpoint's contract
means changing that client, this page and `openapi.json` in the same change set.

## Hosting notes

The resource path is taken from `PATH_INFO`, so the API needs **no rewrite rule and no
server configuration** — `api.php/account` works out of the box on Apache, LiteSpeed and
cPanel-style hosting, which is what WHMCS installs run on.

A few nginx + php-fpm setups drop `PATH_INFO`. Two fixes, either is fine:

- add `fastcgi_split_path_info ^(.+\.php)(/.+)$;` (with `fastcgi_param PATH_INFO
  $fastcgi_path_info;`) to the PHP location block — the standard recipe; or
- call the equivalent query form, `api.php?path=/account`, which routes identically.

Every install serves its own contract at `/openapi.json`, so a partner's portal
always documents exactly the version they are running.

[VpnHood]: https://github.com/vpnhood/VpnHood
