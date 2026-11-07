# In-App Purchases — how money appears in your WHMCS

What the `vpnhoodiap` addon writes into your billing tables, what those numbers
mean, and what they deliberately do **not** mean. Read this before your first
store sale — and hand it to whoever does your accounting.

Part of the [White-Label Guide](https://github.com/vpnhood/VpnHood.WHMCS.Partner/blob/main/docs/WHITE-LABEL.md). Store setup:
[Google Play](IAP-GOOGLE-PLAY.md) · [Apple App Store](IAP-APPLE-APP-STORE.md).

## The one rule everything follows

**The store is the merchant of record.** Google/Apple charge the customer,
keep their cut, handle taxes and refunds, and pay you through their payout
cycle. Your WHMCS never touches the customer's money — the addon only creates
matching records so store customers look and behave like web customers:
a client, an order, a service to provision, and a paid invoice for the books.

Everything the addon writes is filtered to its own bookkeeping gateway,
**`vpnhoodiappay`** (shown as *In-App Purchase*). Your web sales and their invoices
are left alone, with one exception: when a customer deletes their account, their
web billing is cancelled at the end of its paid period, and their unpaid invoices
and stored payment methods are cancelled with it.

## What lands on a store invoice

Every store purchase gets an invoice marked **Paid** at once, and every renewal
marks WHMCS's renewal invoice **Paid** when the store reports it, each with the
store's own order id as the transaction id. Its total follows
one rule — *the truth when it is knowable, an explicit flag when it is not*:

| Case | Invoice total | Example |
| --- | --- | --- |
| Store charged in the client's WHMCS currency | **the real charge** | Play charged $46.99 → invoice $46.99 |
| Store charged in a different currency | **0.00** — the "money lives at the store" flag | Play charged ₺259.99 → invoice 0.00 |
| Real charge unknown (API hiccup) | the WHMCS product's book price | fallback only |

The real charge — including a foreign-currency one — is always written into the
invoice line text (*"The store charged 259.99 TRY, see your store receipt"*)
and shown in **Addons → VpnHood! In-App Purchase → Purchases → "Store Paid"**, so support
can always answer "what did this customer actually pay" without Play Console
access. Amounts are **never converted** between currencies: a converted number
would match neither the customer's receipt nor your payout, so it is not
invented in the first place.

## What your reports will show

- **Counts are always complete**: purchases, renewals, refunds per day/product
  all appear, filterable by the `vpnhoodiappay` gateway.
- **Values are exact for same-currency sales and 0.00 for foreign ones** — so a
  revenue graph that includes store sales *undercounts* foreign revenue by
  design.
- **For real accounting, use the store's payout reports** (Play Console /
  App Store Connect financial reports). They are the only numbers that include
  the store's commission and settled currency conversion. When reconciling,
  exclude the `vpnhoodiappay` gateway from WHMCS revenue and take the store
  payouts instead.
- Store-collected VAT never appears in WHMCS tax reports (invoice lines are
  written untaxed — the store already handled tax).

## Refunds

Refunds are **store-initiated only** (Play Console / App Store — an admin
cannot refund a store customer from WHMCS, there is no store money to move
from here). When the store refunds or revokes a purchase, the addon hears
about it and mirrors it: the service is terminated, the invoice is marked
Refunded, and a reversing transaction is booked for whatever was originally
recorded. Replayed notifications never double-book.

Refunds arrive by push notification. If one is lost, or booking it fails, the
nightly check still catches it, in the same way: it re-reads the open purchases
from the store, and for Google it also sweeps the list of voided purchases. If
the store refunds a charge this install never recorded (a renewal whose
notification never arrived), the service ends but nothing is reversed, and an
admin alert asks you to match the store's refund against your books.

## Renewals

Your WHMCS never charges anyone — there is nothing to charge. The renewal
invoice WHMCS generates simply waits (all customer mail for `vpnhoodiappay`
invoices is suppressed) until the store reports the renewal; the addon then
marks it paid with the new store order id, which is what advances the due date
and extends the service. If a subscription ends at the store, the service is
terminated and its leftover unpaid renewal invoice is cancelled automatically.

## Customer-visible surface

Store customers get **no billing email from WHMCS** — no invoices, reminders,
payment confirmations, or product welcome mail (the store sends its own
receipts). The addon sends only two account notices: when a new sign-in method
is added to an account, and when an account gets a second active subscription.
If a customer logs into your client area, each store purchase's invoice is
marked Paid and names the store; a renewal invoice WHMCS has generated stays
Unpaid until the store reports the renewal.

## If you deactivate the addon

The API and webhook endpoints answer 404, processing stops, and nothing else
in your WHMCS changes. Google protects in-flight purchases itself: an
unacknowledged purchase is automatically refunded after a few days, so a Google
customer is never charged for something that was never delivered. Apple has no
such refund: an Apple purchase made while the addon is off stays charged, and
the buyer gets it once the addon is back, by Restore Purchases in the app.
Tables and history are kept for reactivation.
