# The Portal API

The REST API a VpnHood app uses to sign in, redeem an app-store purchase, and receive
its access code.

The machine-readable contract is [`openapi.json`](../modules/addons/vpnhoodiap/openapi.json),
which ships inside the module and is served by every install at
`GET /openapi.json` — point Swagger UI or a client generator straight at it. This
page is the narrative version: what the endpoints are for, and why they behave as they do.

```text
Base URL   https://<whmcs>/modules/addons/vpnhoodiap/api.php
Resource   /v1/<controller>/<resource> (the path after api.php — PHP PATH_INFO)
Auth       Authorization: Bearer <session token>
Success    the resource itself, as JSON — no envelope
Failure    RFC 9457 application/problem+json, with a stable `code`
```

The vocabulary is deliberately backend-neutral — sessions, accounts, products, purchases,
subscriptions, access codes. **No WHMCS concept ever appears on the wire**: no client,
order, invoice or service id, and no WHMCS error text. A different backend can implement
this same document and the apps will not know the difference.

## Endpoints

| | Endpoint | Auth | Purpose |
| --- | --- | :---: | --- |
| `GET` | `/openapi.json` | — | This API's OpenAPI 3.1 document |
| `GET` | `/v1/system/status` | — | Is the portal installed, active and healthy |
| `POST` | `/v1/auth/sessions` | — | Sign in → session token. Three request forms: Google/Apple id token, the WHMCS client-area password, or a second-factor challenge completion |
| `DELETE` | `/v1/auth/sessions/current` | ✔ | Sign out (revokes the token server-side) |
| `GET` | `/v1/account` | ✔ | The complete account snapshot: identity, THE one access code serving it, and the store subscription behind it |
| `DELETE` | `/v1/account` | ✔ | Delete the account everywhere. Never touches a store subscription |
| `PUT` | `/v1/account/access-code` | ✔ | Fill, replace or empty the account's ONE upload slot (`accessCode: null` empties it). Answers 204 — the code is taken on trust |
| `POST` | `/v1/account/access-code/rejected` | ✔ | A device reports that the access server refused the code it was serving; applied only while that is still the account's current code |
| `GET` | `/v1/billing/products?store=&packageName=` | — | The store product ids this app may sell in that store |
| `POST` | `/v1/billing/purchases` | ✔ | Redeem a store purchase; `GET /v1/account` then carries what it delivered |

A path that exists but is called with the wrong method answers **405** with an `Allow`
header, never 404 — so an integrator can tell a wrong URL from a wrong verb.

Every resource hangs off a controller. `/openapi.json` is the deliberate exception:
tooling expects an API's document at its root, so that is where it lives.

### The version in the path

Every resource sits under a major-version segment, `/v1`. An app already published to a
store can never be force-updated, so the day this API has to change shape incompatibly,
`/v2` is served **beside** `/v1` and every install in the wild keeps working untouched —
without that segment the only escape would be a new endpoint per breaking change, and the
API would accumulate them one resource at a time.

Within a version, changes stay additive: new fields may appear in any response at any
time, and clients must ignore the ones they don't know (the official client does). Only a
change that would break a correct client earns a new segment.

`/openapi.json` stays unversioned at the root, where tooling expects an API's document.
The document's own `info.version` is a different axis — it dates the contract, and moves
for additive changes too.

## Authentication

`POST /v1/auth/sessions` exchanges an identity provider's id token for a **portal session
token**: 64 hex characters, valid 30 days, stored only as a SHA-256 hash, revocable at
any time. It is deliberately *not* a JWT — there are no signing keys to manage and a
sign-out is real, not just a client-side forget.

Send it on every other call:

```http
Authorization: Bearer 0f1e2d3c4b5a…
```

`GET /v1/billing/products` is the one resource outside `/auth` and `/system` that takes no
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

### The password form

The same `POST /v1/auth/sessions` also takes `{email, password, packageName}` — the WHMCS
client-area credentials — and returns the same session token. One session concept, not
two. Three rules govern this form:

- **It never creates an account.** The provider form deliberately creates one for a new
  email; the password form only ever signs into something that already exists — an app
  account (matched by its `whmcs` identity, by a client the login owns, or by a
  WHMCS-verified email), or the WHMCS client itself for a pure web customer, whose
  app-side record is then created already bound to that client. A login that owns no
  client and matches nothing is refused (`no_account`).

- **It cannot be used to scan emails.** An unknown email, a wrong password and an
  account that never set one (store-created accounts never do) are ONE answer:
  `invalid_credentials`, identical in status, body and timing — unknown emails burn the
  same bcrypt time as real ones. After repeated failures the address cools down
  (`too_many_attempts`): it waits out the configured minutes (10 by default, addon
  setting *Password Cooldown*) and then works again by itself — no lock to lift, and
  nonexistent addresses cool down exactly like real ones. Setting or recovering the
  password on the account website is the way in.

- **The second factor is honored, in the API.** When the account uses WHMCS two-factor
  auth, the password form answers **200** `{challenge: {token, type, expiresAt}}`
  instead of a session. Complete it with the third form,
  `{challengeToken, code, packageName}`: the authenticator code or the account's backup
  code, on the same step. The challenge token is not a session — single-use, minutes
  long, a small attempt budget, and it can do nothing but complete its own challenge
  (`invalid_code` while attempts remain, `invalid_challenge` once it is spent). A spent
  backup code is rotated: the 201 carries `newBackupCode` once, and nothing ever shows
  it again. Verification runs through WHMCS's own two-factor machinery, so the TOTP
  replay guard and time-window tolerance are theirs, not a re-implementation.

## The purchase flow

```text
1. POST /auth/sessions       → { accessToken, userId, … }
2. buy in the store, passing userId as
   obfuscatedAccountId (Google) / appAccountToken (Apple)
3. POST /billing/purchases   → "provisioned"
4. GET /account              → { …, accessCodeInfo: { accessCode, … }, subscription: { … } }
5. redeem accessCodeInfo.accessCode in the client — premium is on
```

One synchronous call, no polling — and the purchase response carries the state
ALONE. The delivered code and the subscription live on `GET /v1/account`, the one
snapshot the app renders from; repeating them here would be a second source of
truth for the same facts. Everything after that — renewals, cancellations,
refunds — arrives as a store webhook, so the snapshot is always the current
truth; the app does not have to track subscription state itself.

**Which subscription, when the account holds more than one.** One person can be
subscribed in two stores at once, and only the store that sold a subscription can
manage, renew or cancel it. So the snapshot answers with **this device's own
store first**: the session remembers the store the device signed in with (from its
package name), and a serving subscription from that store outranks any other. The
newest across all stores is the fallback — for a device whose store sold nothing,
and for sessions issued before the session carried a store. Without this an
Android device could be handed an Apple subscription's code: its own Google
subscription would vanish from the snapshot, a Google purchase would be refused as
"already premium", and the app would offer no way to manage either.

The snapshot's `subscription` describes what the buyer is on — `createdTime`,
`isAutoRenew`, `priceAmount` + `priceCurrency`, and `billingPeriod` as an
ISO-8601 duration (`P1M`, `P1Y`, …) — so an app renders the subscription summary
without ever asking the store a second time for what it already paid. The price
is the **store's** figure for the current period, not a portal catalogue price:
the two differ whenever the store rounds to its own local price points, and what
the buyer was actually charged is the one worth showing.

Three properties worth knowing before integrating:

- **The proof is a pointer, not evidence.** Whatever the store handed the app is only
  used to look the purchase up: the portal re-fetches it from the store's own API and
  acts on *that*. A forged body buys nothing.
- **The buyer must be the signed-in user.** The purchase's store attribution
  (`obfuscatedAccountId` / `appAccountToken`) must equal the session's `userId`, or the
  call fails with `purchase_account_mismatch`. A stolen purchase token cannot be
  redeemed into another account.
- **Redeeming twice is safe.** The store purchase key is the idempotency anchor: a
  retry answers `provisioned` for the same order and never creates a second one.
  Retry freely after a network failure.

### The purchase state

`POST /v1/billing/purchases` always answers **201** — the purchase is recorded either way — and
the body *is* the state:

| Body | Meaning | What the client should do |
| --- | --- | --- |
| `"provisioned"` | Delivered | Refresh `GET /v1/account`; it carries the code |
| `"pending"` | The store has not settled the payment (deferred/slow payment methods) | Retry shortly |

The state is deliberately not encoded in the status line as well. `pending` is a fact about
the *store* settling a payment, not about what this request did to a resource, and a second
copy in the status is one more thing to keep true — clients would still have to read the
body, since most HTTP stacks treat every 2xx alike.

A purchase is never held up for a portal-side email confirmation. The identity provider
has already proved the mailbox — sign-in is refused otherwise — so asking the customer
to confirm the same address again before delivering what they just paid for buys
nothing. Where a purchase attaches to a customer account that existed *before* it, the
portal closes that account's **web area** until it confirms the address, which keeps
someone who pre-registered another person's address from reading their account. That is
a portal-side concern only: this API neither reports it nor gates on it, and the
subscription works in the app throughout.

**A paid purchase is never refused for being a second one.** Prevention happens in the
app, before the store's payment sheet opens: checkout is not offered to an account the
server says is already served. Whatever still arrives paid is provisioned — the account
then holds both, each serving its own store's devices — and an administrator is alerted
so it can be surfaced to the customer instead of unwound by force. An upgrade or
resubscribe is not a second subscription either: it carries the purchase it replaces.

This reverses an earlier `409` that left the purchase **unacknowledged** so the store
would auto-refund it. That is a Google Play behaviour, not a universal one: Apple has no
acknowledgement deadline, no automatic refund and no cancel we can call, so there a
refusal is simply the buyer's money kept for nothing. The unacknowledged fail-safe still
covers genuine provisioning *failures* — those deliver nothing, so there is nothing to
keep the money for.

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
| `code_not_found` | 404 | RETIRED for `PUT /v1/account/access-code`: a code is taken on trust and validity is the access server's verdict. Still answered by the client-area `isAutoSelectable` control for a service the account does not hold |
| `method_not_allowed` | 405 | Wrong verb for an existing resource (see `Allow`) |
| `purchase_unattributed` | 409 | No attribution the portal can resolve to an account; recorded for an admin |
| `purchase_inactive` | 410 | Expired, cancelled or refunded at the store |
| `plan_not_available` | 422 | The store product is not mapped to a plan here; parked, admin alerted |
| `deletion_blocked` | 409 | RETIRED (kept for old clients' error maps): deletion no longer blocks — web billing is cancelled at period end instead |
| `rate_limited` | 429 | Too many requests from this address |
| `store_not_supported` | 501 | Known store, not implemented on this portal yet |
| `provisioning_failed` | 502 | Downstream provisioning failed; nothing half-created, safe to retry |
| `deletion_failed` | 502 | Anonymization or invoice cleanup failed; nothing partial, safe to retry |
| `purchase_in_progress` | 503 | Another request is redeeming this same purchase; retry shortly |
| `internal_error` | 500 | Unexpected; recorded in the module log |

Unrecognised codes should be treated as a generic failure of their status class — new
ones may be added.

**Rate limits** (sliding window, per IP): `POST /v1/auth/sessions` 20 per 5 min,
`POST /v1/billing/purchases` 30 per 5 min, `GET /v1/system/status` 30 per min,
`DELETE /v1/account` 5 per 5 min, `PUT /v1/account/access-code` 10 per 5 min, and
`POST /v1/account/access-code/rejected` 30 per 5 min — each per IP address and per account.

## The ranking, and the one upload slot

An account never *owns* a code — it **holds** codes (a code is a bearer credential with
its own device limit, enforced by the access manager wherever it is used). All of them are
treated the same way, whichever of the three channels put them there: a store
subscription, a portal-store purchase, or the one code the person typed and uploaded.
**The app is told a code, not a list.** No inventory ever crosses to a device; the list
lives in the client area, next to the invoices.

**Nothing is stored as "the" selection.** The winner is recomputed on every read, so a
code that dies leaves nothing to repair — and the order is **deterministic**, with no dates
in it:

0. **The store subscription** — this device's own store first, and only while the store is
   still charging for it. A subscription that has ended is not a candidate at all: we ended
   it, so its code stops being one of this person's codes at that moment.
1. **A portal code with live recurring billing.** Somebody who is paying never does code
   management.
2. **The imported code** — somebody typed it in, and typing a code is saying *use this
   one*, so it wins over anything nobody is being billed for and never over anything that
   is. Whoever wants their own code ahead of a subscription signs out.
3. **The other portal codes.**
4. **Nothing.**

Within a group, a code whose clock has already started comes before one that has not — an
unused one-time code is worth more unspent — and then oldest purchase first.

**One function decides all of it**, store subscription included. That is deliberate: while
the subscription was chosen separately, a rejection report was compared against one answer
and the account was served from another, so a refused subscription code matched nothing and
could never be retired.

**Every code is either eligible or rejected**, and that is the whole model. Only an
access-server refusal sets it — an expiry this install can read is *display*, never a
verdict, because a clock that can retire a code can equally start an **unused** one early
and a prepaid code begins its life on first use. Consumption order is not optimised at all,
deliberately: it cost a table, an endpoint and a per-account trust argument to save a code
from expiring slightly sooner.

**A rejection never skips what is being paid for right now.** Downgrading a paying person
to a lesser code would hide a provisioning fault behind a worse credential; the refusal is
recorded and shown in the client area instead. It is also what makes renewal recover by
itself — a renewed service is paid-now again, so its code is offered again with nothing to
clear by hand.

**A rejection demotes a code; it never takes it away.** The next working code is served, and when
every code an account holds has been refused they TAKE TURNS — least recently refused first, ordered
by the refusal row's own id, which only grows. The account never answers "you hold nothing" while it
holds something: the person does hold those codes, their device keeps its copy either way, and a
second device must not be told a different story. It is also how a topped-up or support-extended
code returns with nothing to press — tried again on its next turn, accepted this time.

A device takes ONE turn per connection attempt: it reports the refusal (which is what moves the
account on), swaps once, and stops there if the next code is refused too. Without that cap a keyring
of dead codes would be walked end to end in a single press, and then walked again for ever.

Only a refusal is softened this way: an ended subscription, a code switched off in the panel and a
dead service are not candidates at all, and a subscription still being paid for is never demoted to
begin with.

This is settled, not a gap: a live subscription whose code the access server refuses is a
**support case**, fixed at the source. The account never substitutes another code the
person holds — that would spend somebody's saved code to cover our own failure and remove
the only sign that anything is wrong. An **ended** subscription is the opposite case and
needs no refusal at all: it stops being one of their codes the moment the paid time is
over.

Two reversible marks steer the ranking and **neither deletes anything**: `isAutoSelectable`,
set in the client area and **true by default**, and `rejected`, set from a device. They are
kept apart on purpose — a system rejection must not erase a deliberate *keep this for
later*, and a retry must not re-arm a code somebody parked. The system never removes a code;
the only thing that leaves is the upload slot's previous occupant.

- `GET /v1/account` carries a single `accessCodeInfo` — `{accessCode, expirationTime}` or
  null — computed by that ranking. `expirationTime` is advisory display; whether a code
  still works is the ACCESS SERVER's verdict at connect time.
  **Reseller stock never qualifies.** A bulk (CSV) order is merchant inventory: it has no
  single code, it is never a personal code, and it is a portal concept the app is
  deliberately not told about. The one place it still matters — the delivered file dies
  with the client-area login — is warned about on the web deletion page and in the final
  email, both server-side.
- **Both writes validate the code's SHAPE, never its existence.** A string that is not an
  access code (version 1, 20 digits, checksum) is a `400`; a well-formed code the portal has
  never issued is stored on trust and settled at use time by the access server.
- **`PUT /v1/account/access-code` fills the account's ONE upload slot**, or empties it when
  `accessCode` is null, and **answers 204 with no body**. This is how a code typed on one
  device becomes usable on all of the person's devices — including iOS, which cannot take a
  typed code. The backend takes any well-formed code **on trust**: validity is settled at
  use time by the access server, never at save time here, so there is no `code_not_found`
  and nothing to inspect in the reply. A promo, admin-issued, partner or MANAGER-issued
  code is saved like any other. Uploading consumes NOTHING — the code keeps working for
  everyone already using it, any number of accounts may hold it, and nothing about billing
  moves. Uploading a code the account already owns does not consume the slot: it turns that
  code back on for the ranking instead, because typing a code is saying *use this*. What
  the account then serves is a separate question, answered by `GET /v1/account`, and it
  need not be the code just uploaded.
- **Emptying the slot removes the uploaded code account-wide.** The same PUT is idempotent.
  Every signed-in device applies the change on its next successful account refresh.
  Emptying deletes only the account's copy — the bearer code itself keeps working and may
  be uploaded again. Purchased subscription codes are not touched by this endpoint. A
  failed refresh never means removal: devices retain their last good account snapshot until
  the portal answers successfully.
- **`POST /v1/account/access-code/rejected` is the only thing a device ever reports.** One
  bit: the access server refused the code it was serving. No reason, no expiry, no
  observation time, and no successful-connection counterpart — every code is simply
  **eligible** or **rejected**, and the ranking never asked for more than that.
- **The code travels in the authenticated body, never in the URL**, and is redacted out of
  the audit log like every other secret. Only a fingerprint is stored: recording that a
  credential stopped working must not give it a second home.
- **A report applies only while it is still about the account's current code**, compared
  atomically before anything changes, so a delayed refusal overtaken by a different code
  does nothing. One case slips through deliberately: remove-then-re-add of the *same*
  string, where a late report lands on the restored code. Telling those two incarnations
  apart would cost a whole code-identity system; the recovery is one more Retry. The answer
  is 204 either way — the device can do nothing useful with the difference.
- **A rejection covers every entry holding that code.** Identical access codes are the same
  credential, so the upload slot and any service delivering the same string are skipped
  together. Recorded **per account**, never globally: that bearer string may be serving
  somebody else perfectly well.
- **Rejection skips a code, it never deletes one.** `PUT /v1/account/access-code` with the
  same code clears it — which is the whole of "Retry", with no second endpoint — and the
  client area can clear it by hand.

## Account deletion

`DELETE /v1/account` is the account deletion the app stores and GDPR require: sessions on
every device, sign-in identities and the account row are erased in one call, and the
customer record behind the retained invoices is anonymized and closed. Signing in
again later creates a brand-new empty account — there is no account restore, and no
identifier of the deleted person is kept (the purchase ledger and the deletion
journal retain only numeric row ids).

One thing IS restorable, deliberately: a still-active **store purchase**. Deletion
never cancels the store subscription, so its owner keeps paying — and Restore
Purchases from a new account presents the store's own proof, which re-attaches the
purchase to that account and re-delivers the **same** subscription and access code
(see `POST /v1/billing/purchases`). Never a new order or code, only when the previous
owner is journalled as deleted, and only onto an account with no other live
subscription — deletion cannot be used to mint anything.

What deletion deliberately does **not** do: it never terminates a running code — an
access code is an open gate with no personal data, and the paid period keeps working
until its own clock ends it. And it **never touches a store subscription**, not even
where the store would let us: signing in again brings the subscription back by
itself, so cancelling it on the way out would destroy the very asset a return
depends on — the person cancels in their own store, and the farewell mail says so.
Paid invoices are retained under legal duty; unpaid ones are cancelled so nothing
can ever bill the deleted person.

**Nothing blocks deletion.** Active WEB services (sold on the portal's own site) do
not refuse it: every recurring one is set to cancel at the END of its paid period
(no renewal invoice is ever generated; the code runs out the time already bought),
stored payment methods are dropped, and the deletion journal keeps the gateway
agreement references so a stray charge can always be traced to an agreement an
administrator can cancel.

**The screen warns, the mail delivers.** There is no deletion-preview endpoint,
deliberately (lifecycle §5/§10): the confirmation a device shows lists no codes and
no counts — a list read once under pressure saves nobody, and fetching it would drag
the whole inventory question into the app. Instead, before anything is erased, one
final message goes to the address carrying every code the person paid for, one last
time — an inbox is searchable a year later, which is when the code is wanted. The
same deletion is available on the web at
`index.php?m=vpnhoodiap&action=delete-account`, so it works without the app
installed (Play policy) — and that page carries the reseller warning the app is not
given: a bulk order's CSV is served by the client area, so it can never be downloaded
again once the account is gone.

**No store is ever displayed.** One app ships on every platform and naming a
competing store is itself a store violation (App Review 2.3.10). Where a
*management link* is needed, the app compares the snapshot's `subscription.storeId`
with its own build and offers the link only on a match — comparing, never
displaying, is the rule.

**Fail closed.** While the addon is deactivated, *every* endpoint answers 404. A client
that gets 404 from `/system/status` is talking to a WHMCS with no portal configured —
not a portal that is merely busy.

## Try it

```bash
PORTAL=https://whmcs.example.com/modules/addons/vpnhoodiap/api.php

# is it alive?
curl -s $PORTAL/v1/system/status

# sign in (id token from the app's Google/Apple sign-in)
TOKEN=$(curl -s -X POST $PORTAL/v1/auth/sessions \
  -H 'Content-Type: application/json' \
  -d '{"provider":"google","idToken":"'"$ID_TOKEN"'","packageName":"com.vpnhood.connect.android"}' \
  | jq -r .accessToken)

# the whole account: identity, its access code, its subscription
curl -s $PORTAL/v1/account -H "Authorization: Bearer $TOKEN"

# redeem a purchase
curl -s -X POST $PORTAL/v1/billing/purchases \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"storeId":"googleplay","packageName":"com.vpnhood.connect.android",
       "proof":{"purchaseToken":"'"$PURCHASE_TOKEN"'"}}'
```

To browse the API instead, open any Swagger UI against
`https://whmcs.example.com/modules/addons/vpnhoodiap/api.php/openapi.json`.

## The client

The reference implementation is **`VpnHood.AppLib.Portal`** in the [VpnHood] repo —
`PortalApiClient` (the typed stub; problem+json surfaces as the toolkit's
standard `ApiException`, machine code in `Data["Code"]`),
`PortalAuthenticationProvider` (sessions), `PortalAccountProvider` (the account
snapshot, and the owner of both the catalog and the order processor) and
`PortalOrderProcessor` (purchases). The catalog side is why `/billing/products` matters to
the client: neither StoreKit nor Play Billing can list an app's own products, so the app
asks the portal which ids to price. Changing an endpoint's contract
means changing that client, this page and `openapi.json` in the same change set.

## Hosting notes

The resource path is taken from `PATH_INFO`, so the API needs **no rewrite rule and no
server configuration** — `api.php/v1/account` works out of the box on Apache, LiteSpeed and
cPanel-style hosting, which is what WHMCS installs run on.

A few nginx + php-fpm setups drop `PATH_INFO`. Two fixes, either is fine:

- add `fastcgi_split_path_info ^(.+\.php)(/.+)$;` (with `fastcgi_param PATH_INFO
  $fastcgi_path_info;`) to the PHP location block — the standard recipe; or
- call the equivalent query form, `api.php?path=/v1/account`, which routes identically.

Every install serves its own contract at `/openapi.json`, so a partner's portal
always documents exactly the version they are running.

[VpnHood]: https://github.com/vpnhood/VpnHood
