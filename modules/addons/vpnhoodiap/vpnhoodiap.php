<?php

/**
 * VpnHood! IAP (In-App Purchase)
 *
 * Turns store purchases (Google Play now; Apple App Store and Microsoft Store later) into
 * real WHMCS clients + orders + paid invoices, and delivers the access code through the
 * install's own provisioning module. Provisioning-agnostic by design: on the hub the mapped
 * products are "vpnhoodstore" (direct), on a partner install they are "vpnhoodpartner"
 * (relayed to the hub) — this module never talks to the access server or the hub itself.
 *
 * Ships in BOTH the hub package and the partner package, inactive by default. Only installs
 * with their own store apps (the hub, white-label partners) activate it. The public
 * endpoints (api.php, webhook.php) fail closed while the addon is not activated.
 *
 * @see modules/servers/vpnhoodstore/    hub provisioning path (reused, never modified)
 * @see modules/addons/vpnhoodpartnerhub whose api.php/repository/CSRF patterns this module follows
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/IapRepository.php';

use WHMCS\Module\Addon\VpnHoodIap\IapRepository;

/**
 * Addon configuration / metadata.
 */
function vpnhoodiap_config(): array
{
    return [
        'name'        => 'VpnHood! In-App Purchase',
        'description' => 'Processes app-store purchases (Google Play / Apple / Microsoft) into WHMCS clients, orders and paid invoices, delivering VpnHood access codes through the install\'s provisioning module.',
        'version'     => '1.2.0',
        'author'      => 'VpnHood',
        'fields'      => [
            'AdminAlertEmail' => [
                'FriendlyName' => 'Admin Alert Email',
                'Type'         => 'text',
                'Size'         => '40',
                'Description'  => 'Receives the daily digest of parked purchases and repeated webhook failures. Leave blank to disable.',
                'Default'      => '',
            ],
            'RawPayloadRetentionDays' => [
                'FriendlyName' => 'Raw Payload Retention (days)',
                'Type'         => 'text',
                'Size'         => '6',
                'Description'  => 'Raw store payloads on purchase records are cleared after this many days (privacy/retention).',
                'Default'      => '90',
            ],
            'TerminateGraceDays' => [
                'FriendlyName' => 'Expiry Grace (days)',
                'Type'         => 'text',
                'Size'         => '6',
                'Description'  => 'Days to keep a service suspended after store-side expiry before terminating.',
                'Default'      => '3',
            ],
            'PasswordCooldownMinutes' => [
                'FriendlyName' => 'Password Cooldown (minutes)',
                'Type'         => 'text',
                'Size'         => '6',
                'Description'  => 'After 5 failed password sign-ins, the address waits this long before it may try again. Applies to nonexistent addresses exactly as to real ones (anti-enumeration).',
                'Default'      => '10',
            ],
        ],
    ];
}

/**
 * Create database tables on activation. Only creates tables that do not exist,
 * so deactivate/reactivate cycles are harmless.
 */
function vpnhoodiap_activate(): array
{
    try {
        $schema = Capsule::schema();

        // one row per configured store app (per store, per package): credentials are
        // stored encrypted via localAPI EncryptPassword and never rendered back.
        if (!$schema->hasTable('mod_vpnhood_iap_apps')) {
            $schema->create('mod_vpnhood_iap_apps', function ($table) {
                $table->increments('id');
                $table->string('store', 16); // googleplay | appstore | microsoft
                $table->string('package_name'); // android package / apple bundle id / ms store id
                $table->text('oauth_client_ids')->nullable(); // comma separated aud allowlist for sign-in idTokens
                $table->text('credentials')->nullable(); // encrypted JSON, store-specific (SA key / .p8 / AAD)
                $table->string('pubsub_service_account')->nullable(); // googleplay: OIDC push identity
                $table->string('webhook_token', 64)->unique(); // secret path token for webhook.php
                $table->enum('status', ['active', 'disabled'])->default('active');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['store', 'package_name']);
            });
        }

        // catalog: plan-granularity mapping, store identifiers -> (whmcs product, cycle).
        // Several rows may share one whmcs_product_id (its different cycles); a bundle SKU
        // may map to several rows and yields several orders/codes.
        if (!$schema->hasTable('mod_vpnhood_iap_products')) {
            $schema->create('mod_vpnhood_iap_products', function ($table) {
                $table->increments('id');
                $table->integer('app_id')->unsigned()->index();
                $table->string('store_product_id');
                $table->string('store_base_plan_id')->default(''); // googleplay only; '' elsewhere
                $table->integer('whmcs_product_id')->unsigned();
                $table->integer('billing_cycle_months')->unsigned()->default(1); // 0 = one-time
                $table->boolean('enabled')->default(true);
                $table->unique(['app_id', 'store_product_id', 'store_base_plan_id'], 'iap_products_app_sku_plan');
            });
        }

        // one row per signed-in store identity; client_id stays null until the email is
        // attached — at sign-in when a WHMCS client already holds it, otherwise at the
        // first purchase, which creates one.
        if (!$schema->hasTable('mod_vpnhood_iap_users')) {
            $schema->create('mod_vpnhood_iap_users', function ($table) {
                $table->increments('id');
                // THE ACCOUNT IS THE PERSON. Sign-in proofs live in
                // mod_vpnhood_iap_identities (several per account): a known
                // (provider, subject) always wins, and a new provider joins an
                // account by proving an address one of its identities CURRENTLY
                // reports. email/provider/provider_subject here only mirror the
                // most recent sign-in — contact and display, never resolution.
                $table->string('provider', 16); // google | apple | microsoft (last sign-in)
                $table->string('provider_subject');
                $table->string('email');
                $table->string('display_name')->nullable(); // latest name the IdP presented; synced onto the client
                $table->boolean('email_verified_claim')->default(false);
                // set when a purchase attached to a pre-existing WHMCS client whose
                // address WHMCS had not confirmed: the client area stays shut for that
                // account until it does. Never gates the purchase itself.
                $table->boolean('requires_email_verification')->default(false);
                $table->integer('client_id')->unsigned()->nullable()->index();
                $table->string('external_uid', 36)->unique(); // UUID: GooglePlay obfuscatedAccountId AND Apple appAccountToken
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index('email'); // plain index: a contact snapshot may legitimately collide
                $table->index(['provider', 'provider_subject']);
            });
        }

        // sign-in proofs: several per account. This is what keeps an account stable
        // when a provider changes its email, and what "link another sign-in method"
        // will append to later.
        if (!$schema->hasTable('mod_vpnhood_iap_identities')) {
            $schema->create('mod_vpnhood_iap_identities', function ($table) {
                $table->increments('id');
                $table->integer('user_id')->unsigned()->index();
                $table->string('provider', 16);
                $table->string('provider_subject');
                $table->string('email'); // the address this identity presented last
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['provider', 'provider_subject'], 'iap_identities_provider_subject');
                $table->index('email'); // the resolution lookup (IapRepository::findUsersByIdentityEmail)
            });
        }

        // opaque app session tokens, hashed at rest.
        if (!$schema->hasTable('mod_vpnhood_iap_sessions')) {
            $schema->create('mod_vpnhood_iap_sessions', function ($table) {
                $table->increments('id');
                $table->integer('user_id')->unsigned()->index();
                $table->string('token_hash', 64)->unique();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                // the store this device belongs to, from the package name it signed in with:
                // its subscription is the one that serves this device (lifecycle §8)
                $table->string('store', 16)->nullable();
            });
        }

        // the purchase ledger / state machine. purchase_key: googleplay purchaseToken,
        // appstore originalTransactionId, microsoft collections item id.
        if (!$schema->hasTable('mod_vpnhood_iap_purchases')) {
            $schema->create('mod_vpnhood_iap_purchases', function ($table) {
                $table->increments('id');
                $table->integer('app_id')->unsigned()->index();
                $table->string('store', 16);
                $table->string('purchase_key');
                $table->string('store_order_id')->nullable()->index(); // client poll key; updated each renewal
                $table->integer('user_id')->unsigned()->nullable()->index();
                $table->integer('client_id')->unsigned()->nullable()->index();
                $table->integer('service_id')->unsigned()->nullable()->index();
                $table->integer('whmcs_order_id')->unsigned()->nullable();
                $table->enum('status', [
                    'pending', 'provisioned',
                    'on_hold', 'canceled', 'expired', 'refunded', 'failed',
                ])->default('pending')->index();
                $table->string('linked_purchase_key')->nullable(); // googleplay resubscribe/upgrade supersession
                $table->timestamp('expiry_time')->nullable();
                $table->decimal('store_amount', 10, 2)->nullable(); // store gross, reconciliation only
                $table->string('store_currency', 8)->nullable();
                $table->boolean('is_test')->default(false);
                $table->boolean('auto_renewing')->default(false);
                $table->mediumText('raw_payload')->nullable(); // retention-capped by cron
                $table->text('last_error')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['store', 'purchase_key'], 'iap_purchases_store_key');
            });
        }

        // webhook inbox: unique (store, message_id) is the dedup that keeps duplicate
        // Pub/Sub deliveries idempotent.
        if (!$schema->hasTable('mod_vpnhood_iap_events')) {
            $schema->create('mod_vpnhood_iap_events', function ($table) {
                $table->increments('id');
                $table->string('store', 16);
                $table->string('message_id');
                $table->string('event_type', 64)->nullable();
                $table->string('purchase_key')->nullable()->index();
                $table->enum('status', ['received', 'processed', 'skipped', 'failed'])->default('received')->index();
                $table->text('error')->nullable();
                $table->mediumText('raw')->nullable();
                $table->timestamp('event_time')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->unique(['store', 'message_id'], 'iap_events_store_msg');
            });
        }

        // request/response audit + rate-limit source (pattern: mod_vpnhood_partner_log).
        if (!$schema->hasTable('mod_vpnhood_iap_log')) {
            $schema->create('mod_vpnhood_iap_log', function ($table) {
                $table->increments('id');
                $table->integer('user_id')->unsigned()->nullable()->index();
                $table->string('action', 64)->nullable();
                $table->string('remote_ip', 64)->nullable()->index();
                $table->integer('http_status')->unsigned()->nullable();
                $table->text('request')->nullable();
                $table->text('response')->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }

        // deletion journal: numeric ids only (never PII — the journal must not be a
        // tombstone), so the anonymization can be re-run mechanically after a
        // backup restore.
        if (!$schema->hasTable('mod_vpnhood_iap_deletions')) {
            $schema->create('mod_vpnhood_iap_deletions', function ($table) {
                $table->increments('id');
                $table->integer('user_id')->unsigned()->nullable();
                $table->integer('client_id')->unsigned()->nullable()->index();
                $table->string('outcome', 32);
                $table->timestamp('created_at')->nullable();
            });
        }

        vpnhoodiap_migrateToAccountKeys(); // claims + refund marks + journal details on fresh installs too
        vpnhoodiap_migrateToInvoiceFreeze();
        vpnhoodiap_migrateToServerChosenCode();
        vpnhoodiap_migrateOffRemovedCodePark();
        vpnhoodiap_migrateToPasswordSignIn();
        vpnhoodiap_migrateToOneImportSlot();
        vpnhoodiap_migrateToKeyring();
        vpnhoodiap_ensureAdminAccess();
        vpnhoodiap_hideGatewayFromCheckout();

        return [
            'status'      => 'success',
            'description' => 'VpnHood IAP tables created successfully.',
        ];
    } catch (\Throwable $e) {
        return [
            'status'      => 'error',
            'description' => 'Unable to create tables: ' . $e->getMessage(),
        ];
    }
}

/**
 * The keyring (access-code-keyring-plan.md). Three additive pieces, all idempotent:
 *
 *  - users.uploaded_access_code — THE one upload slot, as a STORED STRING. It used to be a
 *    claim pointing at a service, which forced the portal to recognise a code before it would
 *    accept one; validity is settled at use time by the access server, never at save time here
 *    (§5), so the slot now holds whatever was typed and the 404 disappears with it;
 *  - claims.is_auto_selectable — DEFAULT TRUE, so an ordinary code needs no decision from
 *    anyone (§3). Turning it off in the panel means the ranking never picks that code — how
 *    somebody protects a code they bought to give away — and it is reversible;
 *  - mod_vpnhood_iap_code_rejections — ELIGIBILITY, the one concept that replaced learned expiry
 *    (§4). A code is eligible until a device reports the access server refusing it, and the row is
 *    keyed by (account, code fingerprint) because identical codes ARE the same credential: the
 *    upload slot and any service delivering that string are skipped together. A repeat refusal
 *    re-inserts the row, so its id is the ORDER of the refusals: once every code an account holds
 *    has been refused they take turns, least recently refused first, and whichever one comes back
 *    to life is tried again by itself. Only the fingerprint
 *    is stored — the credential must not gain a second home. mod_vpnhood_iap_code_expiry is DROPPED
 *    with the idea it served: predicting a clock cost a table, an endpoint and a trust argument to
 *    optimise consumption order, and the ranking never needed more than "skip this one?".
 *
 * The backfill moves each legacy imported claim into the slot it became, then drops the claim.
 * Nothing is trimmed silently: an identity holding several is left for vpnhoodiap_migrateToOneImportSlot
 * to report, and only the first is carried across.
 */
function vpnhoodiap_migrateToKeyring(): void
{
    $schema = Capsule::schema();

    if ($schema->hasTable('mod_vpnhood_iap_users') && !$schema->hasColumn('mod_vpnhood_iap_users', 'uploaded_access_code')) {
        $schema->table('mod_vpnhood_iap_users', function ($table) {
            $table->string('uploaded_access_code', 64)->nullable();
        });
    }

    if ($schema->hasTable('mod_vpnhood_iap_claims') && !$schema->hasColumn('mod_vpnhood_iap_claims', 'is_auto_selectable')) {
        $schema->table('mod_vpnhood_iap_claims', function ($table) {
            $table->boolean('is_auto_selectable')->default(true);
        });
    }

    // Eligibility replaces learned expiry (§4). A row here means "a device met a refusal with this
    // code"; no row is the default, so every existing code stays eligible through the migration.
    // An unreleased 1.2.0 dev shape (created_at, updated in place). A rejection is transient
    // state a device re-reports the instant it meets the refusal again, so there is nothing here
    // worth migrating — and the row id has to order the refusals, which an updated row cannot do.
    if ($schema->hasTable('mod_vpnhood_iap_code_rejections')
        && !$schema->hasColumn('mod_vpnhood_iap_code_rejections', 'refused_at')) {
        $schema->drop('mod_vpnhood_iap_code_rejections');
    }
    if (!$schema->hasTable('mod_vpnhood_iap_code_rejections')) {
        $schema->create('mod_vpnhood_iap_code_rejections', function ($table) {
            // the id is not decoration: a refusal is re-inserted, so the id is the ORDER of the
            // refusals, which is what lets refused codes take turns without a clock (§4)
            $table->increments('id');
            $table->integer('user_id')->unsigned()->index();
            // the code is never stored — a fingerprint recognises it again, and a bearer credential
            // must not gain a second home just to record that it stopped working
            $table->string('code_hash', 64);
            $table->timestamp('refused_at')->nullable();
            $table->unique(['user_id', 'code_hash'], 'iap_code_rejections_user_code');
        });
    }

    // The learned-expiry table goes with the mechanism. Nothing reads it, and keeping a per-account
    // record of when somebody's credential dies is not something to hold on to for sentiment.
    if ($schema->hasTable('mod_vpnhood_iap_code_expiry')) {
        $schema->drop('mod_vpnhood_iap_code_expiry');
    }

    vpnhoodiap_backfillUploadSlot();
}

/** Carry each legacy imported claim into users.uploaded_access_code, then drop the claim row. */
function vpnhoodiap_backfillUploadSlot(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_vpnhood_iap_claims') || !$schema->hasColumn('mod_vpnhood_iap_users', 'uploaded_access_code')) {
        return;
    }
    require_once __DIR__ . '/lib/Provisioning/DeliveryReader.php';

    // claims on services the claimer's own client does NOT own = imported (the old slot)
    $imported = Capsule::table('mod_vpnhood_iap_claims as c')
        ->join('tblhosting as h', 'h.id', '=', 'c.service_id')
        ->join('mod_vpnhood_iap_users as u', 'u.id', '=', 'c.user_id')
        ->whereNull('u.uploaded_access_code')
        ->whereRaw('h.userid <> COALESCE(u.client_id, -1)')
        ->orderBy('c.id')
        ->get(['c.id as claim_id', 'c.service_id', 'u.id as user_id']);

    $reader = new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\DeliveryReader();
    $filled = [];
    foreach ($imported as $row) {
        $userId = (int) $row->user_id;
        if (isset($filled[$userId])) {
            continue; // several imported claims: carry the first, leave the rest to the audit
        }
        $code = $reader->readAccessCode((int) $row->service_id);
        if ($code === null) {
            continue; // not provisioned (yet) — leave the claim alone rather than lose the pointer
        }
        Capsule::table('mod_vpnhood_iap_users')->where('id', $userId)->update(['uploaded_access_code' => $code]);
        Capsule::table('mod_vpnhood_iap_claims')->where('id', (int) $row->claim_id)->delete();
        $filled[$userId] = true;
    }
}

/**
 * Migration point for future versions (invoked by WHMCS when the version in
 * vpnhoodiap_config() increases). Keep migrations additive and idempotent.
 */
function vpnhoodiap_upgrade(array $vars): void
{
    vpnhoodiap_migrateToKeyring();
    // installs activated before this ran (API/automation) have no access row and
    // are invisible in the Addons menu until someone notices
    vpnhoodiap_ensureAdminAccess();
    vpnhoodiap_hideGatewayFromCheckout();

    vpnhoodiap_migrateToEmailIdentity();
    vpnhoodiap_migrateToIdentityResolution();
    vpnhoodiap_migrateToAccountKeys();
    vpnhoodiap_migrateToInvoiceFreeze();
    vpnhoodiap_migrateToServerChosenCode();
    vpnhoodiap_migrateOffRemovedCodePark();
    vpnhoodiap_migrateToPasswordSignIn();
    vpnhoodiap_migrateToLinkedIdentities();
    vpnhoodiap_migrateToDisplayName();
    vpnhoodiap_migrateOffEmailVerificationParking();
    vpnhoodiap_migrateToClientAreaVerificationGate();
    vpnhoodiap_migrateToDeletionJournal();
    vpnhoodiap_migrateToSessionStore();
    vpnhoodiap_migrateToOneImportSlot();
}

/**
 * The session learns which store the device belongs to, so GET /account can prefer
 * the subscription that device's own store bills (lifecycle §8). Sessions issued
 * before this migration carry null, which reads as "no home store" and falls back
 * to the account-wide choice — the behaviour they already had. Idempotent.
 */
function vpnhoodiap_migrateToSessionStore(): void
{
    $schema = Capsule::schema();
    if ($schema->hasTable('mod_vpnhood_iap_sessions') &&
        !$schema->hasColumn('mod_vpnhood_iap_sessions', 'store')) {
        $schema->table('mod_vpnhood_iap_sessions', function ($table) {
            $table->string('store', 16)->nullable();
        });
    }
}

/**
 * Audit the one-slot imported-code invariant. The one-slot limit itself is
 * transactional logic because a unique key on user_id would outlaw selection
 * markers on purchased services that share the claims table. Explicit PUT or
 * DELETE heals a legacy overfull identity; migration never silently trims one.
 */
function vpnhoodiap_migrateToOneImportSlot(): void
{
    $schema = Capsule::schema();
    if ($schema->hasTable('mod_vpnhood_iap_claims')) {
        // claims on services the claimer's own client does NOT own = imported
        $overfull = Capsule::table('mod_vpnhood_iap_claims as c')
            ->join('tblhosting as h', 'h.id', '=', 'c.service_id')
            ->leftJoin('mod_vpnhood_iap_users as u', 'u.id', '=', 'c.user_id')
            ->whereRaw('h.userid <> COALESCE(u.client_id, c.client_id, -1)')
            ->selectRaw('COALESCE(c.user_id, -c.client_id) as identity, COUNT(*) as n')
            ->groupBy('identity')->havingRaw('COUNT(*) > 1')->get();
        if ($overfull->isNotEmpty()) {
            logModuleCall('vpnhoodiap', 'oneImportSlotAudit',
                'accounts holding more than one imported claim (decide by hand, nothing was trimmed)',
                $overfull->toJson());
        }
    }

    // Retire the short-lived removal-stamp draft if a development install ran it.
    if ($schema->hasTable('mod_vpnhood_iap_users')) {
        foreach (['import_removed_at', 'import_removed_suffix'] as $retiredColumn) {
            if ($schema->hasColumn('mod_vpnhood_iap_users', $retiredColumn)) {
                $schema->table('mod_vpnhood_iap_users', function ($table) use ($retiredColumn) {
                    $table->dropColumn($retiredColumn);
                });
            }
        }
    }
}

/**
 * The bookkeeping gateway must never be offered at checkout: it collects nothing
 * — the app store is the merchant of record — so a customer who picked it would
 * land on an invoice no one can ever pay. WHMCS keys order-form visibility on the
 * gateway's `visible` setting, which gateway activation defaults ON and any admin
 * can re-tick, so it is clamped here rather than trusted to a checkbox: on
 * activate, on every upgrade, and daily by the cron hook. Second layer: the
 * checkout hook (includes/hooks/vpnhoodiap-hide-gateway.php) strips the gateway
 * from the cart's list regardless of the flag. Floor: the gateway has no capture
 * flow, so whatever slips through can never become a paid invoice or a service.
 * No-op until the gateway itself is activated (no `visible` row yet) — the cron
 * clamp closes that window.
 */
function vpnhoodiap_hideGatewayFromCheckout(): void
{
    Capsule::table('tblpaymentgateways')
        ->where('gateway', 'vpnhoodiappay')
        ->where('setting', 'visible')
        ->update(['value' => '']);
}

/**
 * Installs that predate account deletion lack the journal table. Idempotent;
 * same definition as in vpnhoodiap_activate().
 */
function vpnhoodiap_migrateToDeletionJournal(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_vpnhood_iap_deletions')) {
        $schema->create('mod_vpnhood_iap_deletions', function ($table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned()->nullable();
            $table->integer('client_id')->unsigned()->nullable()->index();
            $table->string('outcome', 32);
            $table->timestamp('created_at')->nullable();
        });
    }
}

/**
 * Installs that predate the client-area gate lack the column. It defaults to
 * false, which is the right answer for every account already on the install:
 * the gate only ever applies from the purchase that attached to a pre-existing
 * WHMCS client onward, never retroactively. Idempotent.
 */
function vpnhoodiap_migrateToClientAreaVerificationGate(): void
{
    $schema = Capsule::schema();
    if ($schema->hasTable('mod_vpnhood_iap_users')
        && !$schema->hasColumn('mod_vpnhood_iap_users', 'requires_email_verification')) {
        $schema->table('mod_vpnhood_iap_users', function ($table) {
            $table->boolean('requires_email_verification')->default(false);
        });
    }
}

/**
 * The WHMCS-side email-verification gate is gone: the identity provider already
 * proves the mailbox, so a purchase never waits on a second confirmation. Rows
 * parked by the old gate are handed back to the pending lane, where the cron's
 * store refresh re-drives them into provisioning on its next pass. Narrowing the
 * enum afterwards is what actually removes the status; MySQL rejects the ALTER
 * while any row still holds the value, so the re-drive has to come first.
 * Idempotent: re-running finds no rows and re-applies the same column type.
 */
function vpnhoodiap_migrateOffEmailVerificationParking(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_vpnhood_iap_purchases')) {
        return;
    }

    Capsule::table('mod_vpnhood_iap_purchases')
        ->where('status', 'awaiting_email_verification')
        ->update(['status' => 'pending', 'last_error' => null]);

    try {
        Capsule::statement(
            "ALTER TABLE `mod_vpnhood_iap_purchases` MODIFY COLUMN `status`"
            . " ENUM('pending','provisioned','on_hold','canceled','expired','refunded','failed')"
            . " NOT NULL DEFAULT 'pending'"
        );
    } catch (\Throwable $e) {
        // the rows are already safe in the pending lane; a column still carrying
        // the dead value only wastes a byte, so never fail an upgrade over it
        logModuleCall('vpnhoodiap', 'upgrade.enumNarrow', '', $e->getMessage(), '');
    }
}

/**
 * Installs that predate display-name capture lack the column; the value itself
 * back-fills naturally on each account's next sign-in. Idempotent.
 */
function vpnhoodiap_migrateToDisplayName(): void
{
    $schema = Capsule::schema();
    if ($schema->hasTable('mod_vpnhood_iap_users')
        && !$schema->hasColumn('mod_vpnhood_iap_users', 'display_name')) {
        $schema->table('mod_vpnhood_iap_users', function ($table) {
            $table->string('display_name')->nullable();
        });
    }
}

/**
 * Installs that predate the identities table have each account's only known proof
 * in the users columns — copy it over so those users resolve by identity (rule 1)
 * instead of falling through to the email match on every sign-in. Idempotent.
 */
function vpnhoodiap_migrateToLinkedIdentities(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_vpnhood_iap_users')) {
        return;
    }
    if (!$schema->hasTable('mod_vpnhood_iap_identities')) {
        $schema->create('mod_vpnhood_iap_identities', function ($table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned()->index();
            $table->string('provider', 16);
            $table->string('provider_subject');
            $table->string('email');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['provider', 'provider_subject'], 'iap_identities_provider_subject');
        });
    }

    $now = date('Y-m-d H:i:s');
    foreach (Capsule::table('mod_vpnhood_iap_users')->get() as $user) {
        if ((string) $user->provider === '' || (string) $user->provider_subject === '') {
            continue;
        }
        $exists = Capsule::table('mod_vpnhood_iap_identities')
            ->where('provider', $user->provider)
            ->where('provider_subject', $user->provider_subject)
            ->exists();
        if (!$exists) {
            Capsule::table('mod_vpnhood_iap_identities')->insert([
                'user_id'          => (int) $user->id,
                'provider'         => $user->provider,
                'provider_subject' => $user->provider_subject,
                'email'            => $user->email,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }
    }
}

/**
 * Normalize account emails for identity-era installs: lowercased/trimmed, and the
 * old (provider, subject) unique key dropped in favour of a plain index.
 *
 * Historically this also keyed accounts on a UNIQUE email. That key is gone —
 * vpnhoodiap_migrateToIdentityResolution() removes it — because resolution now
 * matches the sign-in methods' current addresses, and a stale contact snapshot may
 * legitimately collide with another account's live address. Duplicate addresses
 * are still reported: each is a split person whose purchases deserve a manual
 * merge, and rule 3 of the resolver refuses to guess between them.
 */
function vpnhoodiap_migrateToEmailIdentity(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_vpnhood_iap_users')) {
        return;
    }

    Capsule::statement('UPDATE mod_vpnhood_iap_users SET email = LOWER(TRIM(email)) WHERE email <> LOWER(TRIM(email))');

    $duplicates = Capsule::table('mod_vpnhood_iap_users')
        ->select('email')
        ->groupBy('email')
        ->havingRaw('COUNT(*) > 1')
        ->pluck('email')
        ->all();
    if ($duplicates !== []) {
        logActivity('vpnhoodiap: these addresses have more than one user row (split accounts — merge by hand): '
            . implode(', ', array_slice($duplicates, 0, 20)));
    }

    // The old key has to go, not just stop being used: one provider account whose
    // address changes now legitimately produces a second row, which that unique
    // index would reject.
    try {
        $schema->table('mod_vpnhood_iap_users', function ($table) {
            $table->dropUnique('mod_vpnhood_iap_users_provider_provider_subject_unique');
        });
        $schema->table('mod_vpnhood_iap_users', function ($table) {
            $table->index(['provider', 'provider_subject']);
        });
    } catch (\Throwable $e) {
        // never existed (fresh install) or already dropped
    }
}

/**
 * Resolution moves from the account row's email to the sign-in methods' emails
 * (IapRepository::findOrCreateUser): the account row keeps only a CONTACT snapshot,
 * refreshed at each sign-in. The unique index on users.email has to go — a stale
 * snapshot may legitimately coexist with another account's current address (the
 * owner moved on; someone else now verifiably holds it) — and identities.email
 * gains an index because it is now the lookup. Fresh installs are created in this
 * shape already; each step tolerates re-runs and half-migrated states.
 */
function vpnhoodiap_migrateToIdentityResolution(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_vpnhood_iap_users')) {
        return;
    }
    try {
        $schema->table('mod_vpnhood_iap_users', function ($table) {
            $table->dropUnique('mod_vpnhood_iap_users_email_unique');
        });
    } catch (\Throwable $e) {
        // fresh install or already migrated — the index is already gone
    }
    try {
        $schema->table('mod_vpnhood_iap_users', function ($table) {
            $table->index('email');
        });
    } catch (\Throwable $e) {
        // already indexed
    }
    try {
        $schema->table('mod_vpnhood_iap_identities', function ($table) {
            $table->index('email');
        });
    } catch (\Throwable $e) {
        // already indexed
    }
}

/**
 * The account→key pointer layer (lifecycle §7/§8): claims record that an
 * account holds a key it proved by pasting the code — nothing about billing
 * moves — and the refund marks are the disclosed 24-month one-way fingerprint
 * of refunded accounts. The deletions journal gains a details column for the
 * gateway agreement references and other non-personal breadcrumbs deletion
 * must not destroy.
 */
function vpnhoodiap_migrateToAccountKeys(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_vpnhood_iap_claims')) {
        $schema->create('mod_vpnhood_iap_claims', function ($table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned()->index();
            $table->integer('service_id')->unsigned()->index();
            $table->boolean('is_default')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'service_id'], 'iap_claims_user_service');
        });
    }
    if (!$schema->hasTable('mod_vpnhood_iap_refund_marks')) {
        $schema->create('mod_vpnhood_iap_refund_marks', function ($table) {
            $table->increments('id');
            $table->string('email_hash', 64)->index();
            $table->timestamp('created_at')->nullable()->index();
        });
    }
    if ($schema->hasTable('mod_vpnhood_iap_deletions') && !$schema->hasColumn('mod_vpnhood_iap_deletions', 'details')) {
        $schema->table('mod_vpnhood_iap_deletions', function ($table) {
            $table->text('details')->nullable();
        });
    }
}

/**
 * The server-chosen code (lifecycle §8: the account always has exactly one
 * code, and the server chooses it — the app is handed one code or nothing):
 *
 *  - claims.client_id — the client area lets a pure web customer (no module
 *    account) import and name codes, so a claim may be keyed by the WHMCS
 *    client instead of a module user. user_id becomes nullable for those rows.
 */
function vpnhoodiap_migrateToServerChosenCode(): void
{
    $schema = Capsule::schema();
    if ($schema->hasTable('mod_vpnhood_iap_claims') && !$schema->hasColumn('mod_vpnhood_iap_claims', 'client_id')) {
        $schema->table('mod_vpnhood_iap_claims', function ($table) {
            $table->integer('client_id')->unsigned()->nullable()->index()->after('user_id');
            $table->unique(['client_id', 'service_id'], 'iap_claims_client_service');
        });
        Capsule::statement('ALTER TABLE mod_vpnhood_iap_claims MODIFY user_id INT UNSIGNED NULL');
    }
}

/**
 * Drop the removed-code park (lifecycle §8, reversed 2026-08-14).
 *
 * users.default_cleared_at recorded a deliberate remove-code act in the app, so
 * promotion would not instantly re-elect another owned code — the escape from a
 * purchase refusal. Both the refusal and the app-side remove act are gone: the
 * app owns no remove for an account-applied code (it leaves with the account),
 * and a purchase is never refused after the money moved. Nothing reads or writes
 * the column any more, so it goes rather than lingering as a field that looks
 * meaningful. Dropping is safe and idempotent: no code path depends on it, and a
 * re-run finds it already absent.
 */
function vpnhoodiap_migrateOffRemovedCodePark(): void
{
    $schema = Capsule::schema();
    if ($schema->hasTable('mod_vpnhood_iap_users') && $schema->hasColumn('mod_vpnhood_iap_users', 'default_cleared_at')) {
        $schema->table('mod_vpnhood_iap_users', function ($table) {
            $table->dropColumn('default_cleared_at');
        });
    }
}

/**
 * The password grant (portal sign-in without a WHMCS page):
 *
 *  - login_challenges — the pending-second-factor tokens the password form
 *    hands out. Not sessions: single-use, minutes-long, an attempt budget,
 *    and they can do nothing but complete their own challenge.
 *  - login_attempts — per-address failure rows for the cooldown (after 5
 *    failures the address waits out PasswordCooldownMinutes, then works again
 *    by itself). Only a sha256 of the normalized address is stored; the
 *    address may not even exist here — nonexistent emails must cool down
 *    exactly like real ones, or the cooldown itself would betray which
 *    emails exist.
 */
function vpnhoodiap_migrateToPasswordSignIn(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_vpnhood_iap_login_challenges')) {
        $schema->create('mod_vpnhood_iap_login_challenges', function ($table) {
            $table->increments('id');
            $table->char('token_hash', 64)->unique();
            $table->integer('whmcs_user_id')->unsigned()->index();
            $table->string('package_name', 191);
            $table->smallInteger('attempts')->unsigned()->default(0);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
    if (!$schema->hasTable('mod_vpnhood_iap_login_attempts')) {
        $schema->create('mod_vpnhood_iap_login_attempts', function ($table) {
            $table->increments('id');
            $table->char('email_hash', 64)->index();
            $table->timestamp('created_at')->nullable()->index();
        });
    }
}

/**
 * Frozen invoices (lifecycle §5): WHMCS renders invoices from the LIVE client
 * row, so anonymizing the customer would strip the buyer's name off every past
 * invoice — the exact thing tax-record rules forbid. Deletion therefore
 * archives each invoice exactly as issued, buyer identity included, before the
 * client row is touched. One row per invoice, written once and never updated;
 * nothing in the module reads this table back (restriction is the legal basis:
 * it exists for an auditor, not for support or search).
 */
function vpnhoodiap_migrateToInvoiceFreeze(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_vpnhood_iap_frozen_invoices')) {
        $schema->create('mod_vpnhood_iap_frozen_invoices', function ($table) {
            $table->increments('id');
            $table->integer('invoice_id')->unsigned()->unique();
            $table->integer('client_id')->unsigned()->index();
            $table->mediumText('artifact');
            $table->char('sha256', 64);
            $table->timestamp('created_at')->nullable();
        });
    }
}

/**
 * WHMCS lists an addon under the Addons menu only for the admin roles named in its
 * `access` setting, and only the admin-UI activation flow writes that row. Activate
 * the module any other way — the API, an automated install, a test harness — and it
 * is installed, fully working, and completely invisible, with nothing to indicate
 * why. Granting it here makes activation self-sufficient however it was triggered.
 *
 * This is the only place the module writes a WHMCS core table: `access` lives in
 * tbladdonmodules alongside the module's own settings, and no localAPI command
 * grants it. An existing grant is never touched — widening access is the admin's
 * decision, not ours.
 */
function vpnhoodiap_ensureAdminAccess(): void
{
    $exists = Capsule::table('tbladdonmodules')
        ->where('module', IapRepository::MODULE)
        ->where('setting', 'access')
        ->exists();
    if ($exists) {
        return;
    }

    $roleId = vpnhoodiap_activatingRoleId();
    if ($roleId <= 0) {
        return; // no admin roles defined at all — nothing sensible to grant
    }

    Capsule::table('tbladdonmodules')->insert([
        'module'  => IapRepository::MODULE,
        'setting' => 'access',
        'value'   => (string) $roleId,
    ]);
}

/**
 * The role that should see the module: whoever activated it, or — when nobody is
 * logged in (API/automation) — the first role, which is Full Administrator on any
 * stock install.
 */
function vpnhoodiap_activatingRoleId(): int
{
    $adminId = (int) ($_SESSION['adminid'] ?? 0);
    if ($adminId > 0) {
        $roleId = (int) Capsule::table('tbladmins')->where('id', $adminId)->value('roleid');
        if ($roleId > 0) {
            return $roleId;
        }
    }

    return (int) Capsule::table('tbladminroles')->orderBy('id')->value('id');
}

/**
 * Deactivation preserves all data. The purchase ledger links store purchases to WHMCS
 * services and the app credentials exist nowhere else — dropping them on a routine
 * deactivate/reactivate would orphan every store subscription this install manages
 * (same hard lesson as vpnhoodpartnerhub_deactivate). Reactivation is harmless:
 * activate() only creates tables that do not exist. To remove permanently, drop the
 * mod_vpnhood_iap_* tables manually after uninstalling.
 */
function vpnhoodiap_deactivate(): array
{
    return [
        'status'      => 'success',
        'description' => 'VpnHood IAP deactivated. All data and tables were preserved;'
            . ' the public api/webhook endpoints stop responding until reactivation.',
    ];
}

/**
 * Per-admin-session CSRF token for this addon's state-changing POST forms.
 * (Pattern shared with vpnhoodpartnerhub.)
 */
function vpnhoodiap_csrfToken(): string
{
    if (empty($_SESSION['vpnhoodiap_csrf'])) {
        $_SESSION['vpnhoodiap_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['vpnhoodiap_csrf'];
}

/** Hidden token field to embed in every POST form. */
function vpnhoodiap_csrfField(): string
{
    return '<input type="hidden" name="token" value="' . htmlspecialchars(vpnhoodiap_csrfToken()) . '">';
}

/**
 * Reject a POST whose CSRF token is missing or does not match the session token.
 *
 * @throws \RuntimeException
 */
function vpnhoodiap_assertCsrf(): void
{
    $token = $_POST['token'] ?? '';
    if (!is_string($token) || $token === '' || empty($_SESSION['vpnhoodiap_csrf'])
        || !hash_equals((string) $_SESSION['vpnhoodiap_csrf'], $token)) {
        throw new \RuntimeException('Invalid or expired security token. Please reload the page and try again.');
    }
}

/**
 * Client area output — three pages: the email-confirmation gate (default), the
 * account-deletion page (`action=delete-account`, the web deletion path Google
 * Play requires), and the codes page (`action=codes` — the ONLY picker in the
 * whole product, lifecycle §8/§9: a build that cannot take a typed code has no
 * in-app way to change codes, so naming and importing live here, next to the
 * invoices). All pass the verify-gate hook, which allows every `m=vpnhoodiap`
 * page.
 */
function vpnhoodiap_clientarea(array $vars): array
{
    $action = (string) ($_REQUEST['action'] ?? '');
    if ($action === 'delete-account') {
        return vpnhoodiap_clientareaDeleteAccount();
    }
    return $action === 'codes'
        ? vpnhoodiap_clientareaCodes()
        : vpnhoodiap_clientareaVerifyEmail();
}

/**
 * "Your premium codes" — list, name, and import (lifecycle §8/§9).
 *
 *  - LISTING here is deliberate and allowed: the client area is the portal,
 *    exactly where "someone who owns several codes is already looking". The
 *    app never gets a list — this page is the only listing there is.
 *  - NAMING (set as my code) is the only picker there is. On a build with no
 *    code box (§9) this page is the ONLY way to change codes at all.
 *  - IMPORTING consumes nothing: it records a pointer, the code keeps working
 *    for everyone already using it, and any number of accounts may import the
 *    same code. The label may say "redeem" one day; the behaviour must never.
 */
function vpnhoodiap_clientareaCodes(): array
{
    require_once __DIR__ . '/lib/ApiException.php';
    require_once __DIR__ . '/lib/IapRepository.php';
    require_once __DIR__ . '/lib/Provisioning/DeliveryReader.php';
    require_once __DIR__ . '/lib/Provisioning/OrderProvisioner.php'; // storeLabel for store-billed rows
    require_once __DIR__ . '/lib/Provisioning/AccountKeyService.php';

    $clientId = (int) ($_SESSION['uid'] ?? 0);
    $email = $clientId > 0
        ? (string) Capsule::table('tblclients')->where('id', $clientId)->value('email')
        : '';

    if (empty($_SESSION['vpnhoodiap_ca_csrf'])) {
        $_SESSION['vpnhoodiap_ca_csrf'] = bin2hex(random_bytes(16));
    }
    $error = '';
    $notice = '';

    $repo = new IapRepository();
    $moduleUser = $email !== '' ? $repo->findUserByEmail($email) : null;
    // a pure web customer has no module account; a stand-in row keys their
    // claims by client_id instead (the same pattern as the deletion page)
    $keyUser = $moduleUser ?? ['id' => 0, 'client_id' => $clientId];
    if ($moduleUser !== null && $moduleUser['client_id'] === null) {
        $keyUser['client_id'] = $clientId; // signed-in area proves the client link the module may not have yet
    }
    $keyService = new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountKeyService($repo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $clientId > 0) {
        try {
            $token = (string) ($_POST['token'] ?? '');
            if ($token === '' || !hash_equals((string) $_SESSION['vpnhoodiap_ca_csrf'], $token)) {
                throw new \RuntimeException('Invalid or expired security token. Please reload the page and try again.');
            }
            $do = (string) ($_POST['do'] ?? '');
            if ($do === 'auto-select') {
                // The panel's one inventory control (keyring plan §3). Reversible, and it deletes
                // nothing: the ranking simply stops offering that code from the next read.
                $on = ($_POST['isAutoSelectable'] ?? '') === 'yes';
                $keyService->setAutoSelectable($keyUser, (int) ($_POST['serviceId'] ?? 0), $on);
                $notice = $on
                    ? 'That code can be chosen for your devices again.'
                    : 'That code will no longer be chosen automatically. It still works for anyone '
                        . 'who has it, and a device already using it keeps it until it expires.';
            } elseif ($do === 'import') {
                // PUT semantics: this deliberate form submission fills or replaces the account's
                // one upload slot. Nothing is proved here — the access server settles at use time
                // whether the code works (keyring plan §5).
                $keyService->setAccessCode($keyUser, (string) ($_POST['accessCode'] ?? ''));
                $notice = 'The code was saved to your account. Which code your devices are handed is '
                    . 'decided fresh each time they check in — anything you are paying for right now '
                    . 'comes first.';
            } elseif ($do === 'remove-import') {
                if (($_POST['confirm'] ?? '') !== 'yes') {
                    throw new \RuntimeException('Please tick the confirmation box to remove your saved code.');
                }
                $keyService->setAccessCode($keyUser, null);
                $notice = 'Your saved code was removed from this account. The code itself keeps working — '
                    . 'you can add it again any time. Your signed-in devices apply the change at '
                    . 'their next successful account refresh.';
            } elseif ($do === 'retry-import') {
                // The client-area half of Retry (keyring plan §4): put a rejected code back in the
                // ranking. The app's half needs nothing new — typing the code again clears it.
                $keyService->clearRejection($keyUser, (string) ($_POST['accessCode'] ?? ''));
                $notice = 'Your saved code will be offered to your devices again. If the server refuses '
                    . 'it once more, it goes back to being skipped — nothing is deleted either way.';
            }
        } catch (\WHMCS\Module\Addon\VpnHoodIap\ApiException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $codes = [];
    if ($clientId > 0) {
        try {
            // No "active" badge: there is no stored selection to show (keyring plan §2). What each
            // row carries is the one thing the panel decides — whether the ranking may pick it.
            $codes = $keyService->webKeysForUser($keyUser);
        } catch (\Throwable $e) {
            logModuleCall('vpnhoodiap', 'clientarea.codes', (string) $clientId, $e->getMessage(), '');
            $error = $error !== '' ? $error : 'Your codes could not be listed right now. Please try again later.';
        }
    }

    return [
        'pagetitle'    => 'Your premium codes',
        'breadcrumb'   => ['index.php?m=vpnhoodiap&action=codes' => 'Your premium codes'],
        'templatefile' => 'account-codes',
        'requirelogin' => true,
        'vars'         => [
            'email'  => $email,
            'error'  => $error,
            'notice' => $notice,
            'csrf'   => $_SESSION['vpnhoodiap_ca_csrf'],
            'codes'  => $codes,
        ],
    ];
}

/**
 * The email-confirmation gate. A purchase that attached itself to a WHMCS client
 * which already existed leaves that account's portal shut until WHMCS confirms the
 * address (see the verify-gate hook); this is the page it is sent to. It exists to
 * be escapable: WHMCS's verification link lives 60 minutes, so the one action here
 * is to send a fresh one. Nothing about the purchase is gated — the subscription is
 * already live in the app while this page is showing.
 */
function vpnhoodiap_clientareaVerifyEmail(): array
{
    require_once __DIR__ . '/lib/Provisioning/AccountService.php';

    $clientId = (int) ($_SESSION['uid'] ?? 0);
    $email = $clientId > 0
        ? (string) Capsule::table('tblclients')->where('id', $clientId)->value('email')
        : '';
    $sent = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'resend' && $email !== '') {
        $sent = (new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountService())
            ->sendVerificationEmail($email);
    }

    return [
        'pagetitle'    => 'Confirm your email address',
        'breadcrumb'   => ['index.php?m=vpnhoodiap' => 'Confirm your email address'],
        'templatefile' => 'verify-email',
        'requirelogin' => true,
        'vars'         => [
            'email'    => $email,
            'resent'   => $sent,
            'attempted' => $_SERVER['REQUEST_METHOD'] === 'POST',
        ],
    ];
}

/**
 * "Delete my account" — the same engine as the app's DELETE /account, reachable
 * on the web so deletion works without the app installed (Play policy). CSRF'd,
 * double-confirmed, and refused with the same actionable message while active
 * web services exist. On success the WHMCS session is logged out — the account
 * behind it no longer exists.
 */
function vpnhoodiap_clientareaDeleteAccount(): array
{
    require_once __DIR__ . '/lib/ApiException.php';
    require_once __DIR__ . '/lib/IapRepository.php';
    require_once __DIR__ . '/lib/Provisioning/DeliveryReader.php';
    require_once __DIR__ . '/lib/Provisioning/AccountKeyService.php';
    require_once __DIR__ . '/lib/Provisioning/AccountDeletionService.php';

    $clientId = (int) ($_SESSION['uid'] ?? 0);
    $email = $clientId > 0
        ? (string) Capsule::table('tblclients')->where('id', $clientId)->value('email')
        : '';

    if (empty($_SESSION['vpnhoodiap_ca_csrf'])) {
        $_SESSION['vpnhoodiap_ca_csrf'] = bin2hex(random_bytes(16));
    }
    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'delete' && $clientId > 0) {
        try {
            $token = (string) ($_POST['token'] ?? '');
            if ($token === '' || !hash_equals((string) $_SESSION['vpnhoodiap_ca_csrf'], $token)) {
                throw new \RuntimeException('Invalid or expired security token. Please reload the page and try again.');
            }
            if (($_POST['confirm'] ?? '') !== 'yes') {
                throw new \RuntimeException('Please tick the confirmation box first.');
            }
            $repo = new IapRepository();
            $moduleUser = $email !== '' ? $repo->findUserByEmail($email) : null;
            (new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountDeletionService())
                ->deleteClient($clientId, $moduleUser);
            header('Location: logout.php');
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }

    // A reseller confirms HERE, not in the app — so this is the one screen that
    // warns the delivered CSV cannot be served again once the login is gone.
    $bulkOrders = 0;
    if ($clientId > 0) {
        try {
            $repo = new IapRepository();
            $moduleUser = $email !== '' ? $repo->findUserByEmail($email) : null;
            $bulkOrders = (new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountKeyService($repo))
                ->bulkOrderCount($moduleUser ?? ['id' => 0, 'client_id' => $clientId]);
        } catch (\Throwable $e) {
            logModuleCall('vpnhoodiap', 'clientarea.deleteAccount.bulkCount', (string) $clientId, $e->getMessage(), '');
        }
    }

    return [
        'pagetitle'    => 'Delete my account',
        'breadcrumb'   => ['index.php?m=vpnhoodiap&action=delete-account' => 'Delete my account'],
        'templatefile' => 'delete-account',
        'requirelogin' => true,
        'vars'         => [
            'email'      => $email,
            'error'      => $error,
            'csrf'       => $_SESSION['vpnhoodiap_ca_csrf'],
            'bulkOrders' => $bulkOrders,
        ],
    ];
}

/**
 * Admin area output: tabbed UI — Apps (store credentials), Catalog (SKU mappings),
 * Purchases / Events / Log (read-only monitors).
 */
function vpnhoodiap_output(array $vars): void
{
    $repo = new IapRepository();
    $modulelink = $vars['modulelink'];
    $tab = $_REQUEST['tab'] ?? 'apps';
    $notice = '';
    $noticeType = 'info';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            vpnhoodiap_assertCsrf();
            $sub = $_POST['do'] ?? '';
            if ($sub === 'app_save') {
                $appId = (int) ($_POST['id'] ?? 0);
                $data = [
                    'store'                  => IapRepository::assertStore((string) ($_POST['store'] ?? '')),
                    'package_name'           => trim((string) ($_POST['package_name'] ?? '')),
                    'oauth_client_ids'       => trim((string) ($_POST['oauth_client_ids'] ?? '')),
                    'pubsub_service_account' => trim((string) ($_POST['pubsub_service_account'] ?? '')),
                    'status'                 => ($_POST['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active',
                ];
                if ($data['package_name'] === '') {
                    throw new \RuntimeException('Package / bundle name is required.');
                }
                // credentials are write-only: an empty field means "keep the stored value"
                $credentials = trim((string) ($_POST['credentials'] ?? ''));
                if ($credentials !== '') {
                    $data['credentials'] = $repo->encryptSecret($credentials);
                }
                if ($appId > 0) {
                    $repo->updateApp($appId, $data);
                    $notice = 'App updated.';
                } else {
                    $created = $repo->createApp($data);
                    $appId = $created['id'];
                    $notice = 'App created. Webhook URL: <code>'
                        . htmlspecialchars($repo->webhookUrl($created)) . '</code>';
                }
                $noticeType = 'success';
                $tab = 'apps';
            } elseif ($sub === 'app_delete') {
                $repo->deleteApp((int) $_POST['id']);
                $notice = 'App removed. Its catalog mappings were removed too; purchase history is preserved.';
                $noticeType = 'success';
                $tab = 'apps';
            } elseif ($sub === 'product_save') {
                $appId = (int) ($_POST['app_id'] ?? 0);
                $productId = (int) ($_POST['whmcs_product_id'] ?? 0);
                $storeProductId = trim((string) ($_POST['store_product_id'] ?? ''));
                if ($appId <= 0 || $productId <= 0 || $storeProductId === '') {
                    throw new \RuntimeException('App, store product id and WHMCS product are all required.');
                }
                $repo->addProductMapping([
                    'app_id'               => $appId,
                    'store_product_id'     => $storeProductId,
                    'store_base_plan_id'   => trim((string) ($_POST['store_base_plan_id'] ?? '')),
                    'whmcs_product_id'     => $productId,
                    'billing_cycle_months' => max(0, (int) ($_POST['billing_cycle_months'] ?? 1)),
                    'enabled'              => 1,
                ]);
                $notice = 'Catalog mapping added.';
                $noticeType = 'success';
                $tab = 'catalog';
            } elseif ($sub === 'product_delete') {
                $repo->deleteProductMapping((int) $_POST['id']);
                $notice = 'Catalog mapping removed.';
                $noticeType = 'success';
                $tab = 'catalog';
            }
        } catch (\Throwable $e) {
            $notice = 'Error: ' . htmlspecialchars($e->getMessage());
            $noticeType = 'danger';
        }
    }

    if ($notice !== '') {
        echo '<div class="alert alert-' . $noticeType . '">' . $notice . '</div>';
    }

    // -- Tabs ---------------------------------------------------------------
    $tabs = ['apps' => 'Apps', 'catalog' => 'Catalog', 'purchases' => 'Purchases', 'events' => 'Events', 'log' => 'Log'];
    echo '<ul class="nav nav-tabs" style="margin-bottom:15px">';
    foreach ($tabs as $key => $label) {
        $active = $key === $tab ? ' class="active"' : '';
        echo '<li' . $active . '><a href="' . $modulelink . '&tab=' . $key . '">' . $label . '</a></li>';
    }
    echo '</ul>';

    switch ($tab) {
        case 'catalog':
            vpnhoodiap_renderCatalog($repo, $modulelink);
            break;
        case 'purchases':
            vpnhoodiap_renderPurchases($repo);
            break;
        case 'events':
            vpnhoodiap_renderEvents($repo);
            break;
        case 'log':
            vpnhoodiap_renderLog($repo);
            break;
        default:
            vpnhoodiap_renderApps($repo, $modulelink, (int) ($_REQUEST['id'] ?? 0));
    }
}

/** Apps tab: list + create/edit form (credentials write-only). */
function vpnhoodiap_renderApps(IapRepository $repo, string $modulelink, int $editId): void
{
    $apps = $repo->allApps();

    // the two URLs an integrator needs: where the app points, and where the store
    // posts. The contract itself is served from the first one.
    $apiUrl = $repo->portalApiUrl();
    echo '<p class="text-muted" style="margin-bottom:12px">Portal API for your apps: '
        . '<code>' . htmlspecialchars($apiUrl) . '</code> — '
        . '<a href="' . htmlspecialchars($apiUrl) . '/openapi.json" target="_blank" rel="noopener">API document</a></p>';

    echo '<table class="table table-striped"><thead><tr>'
        . '<th>ID</th><th>Store</th><th>Package / Bundle</th><th>Status</th><th>Webhook URL</th><th></th></tr></thead><tbody>';
    if (empty($apps)) {
        echo '<tr><td colspan="6" class="text-center text-muted">No store apps configured yet.</td></tr>';
    }
    foreach ($apps as $a) {
        $badge = $a['status'] === 'active' ? 'success' : 'default';
        echo '<tr>'
            . '<td>' . (int) $a['id'] . '</td>'
            . '<td>' . htmlspecialchars($a['store']) . '</td>'
            . '<td><code>' . htmlspecialchars($a['package_name']) . '</code></td>'
            . '<td><span class="label label-' . $badge . '">' . htmlspecialchars($a['status']) . '</span></td>'
            . '<td><code style="font-size:11px">' . htmlspecialchars($repo->webhookUrl($a)) . '</code></td>'
            . '<td><a class="btn btn-xs btn-default" href="' . $modulelink . '&tab=apps&id=' . (int) $a['id'] . '">Edit</a> '
            . '<form method="post" action="' . $modulelink . '" style="display:inline"'
            . ' onsubmit="return confirm(\'Delete this app and its catalog mappings? Purchases are preserved. This cannot be undone.\');">'
            . vpnhoodiap_csrfField()
            . '<input type="hidden" name="do" value="app_delete">'
            . '<input type="hidden" name="id" value="' . (int) $a['id'] . '">'
            . '<button type="submit" class="btn btn-xs btn-danger">Delete</button></form></td>'
            . '</tr>';
    }
    echo '</tbody></table>';

    $app = $editId > 0 ? $repo->getApp($editId) : null;
    $isEdit = $app !== null;
    echo '<hr><h4>' . ($isEdit ? 'Edit App #' . (int) $app['id'] : 'Add App') . '</h4>';
    echo '<form method="post" action="' . $modulelink . '&tab=apps">';
    echo vpnhoodiap_csrfField();
    echo '<input type="hidden" name="do" value="app_save">';
    echo '<input type="hidden" name="id" value="' . ($isEdit ? (int) $app['id'] : 0) . '">';
    echo '<div class="form-group"><label>Store</label><select name="store" class="form-control">';
    foreach (IapRepository::STORES as $store) {
        $selected = $isEdit && $app['store'] === $store ? ' selected' : '';
        echo '<option value="' . $store . '"' . $selected . '>' . $store . '</option>';
    }
    echo '</select></div>';
    echo '<div class="form-group"><label>Package / Bundle Name</label>'
        . '<input type="text" name="package_name" class="form-control" required value="'
        . ($isEdit ? htmlspecialchars($app['package_name']) : '') . '">'
        . '<p class="help-block">Android package name, Apple bundle id, or Microsoft Store id. Webhook and sign-in requests are matched to this app by it.</p></div>';
    echo '<div class="form-group"><label>OAuth Client IDs (comma separated)</label>'
        . '<input type="text" name="oauth_client_ids" class="form-control" value="'
        . ($isEdit ? htmlspecialchars($app['oauth_client_ids'] ?? '') : '') . '">'
        . '<p class="help-block">Allowed "aud" values for sign-in id tokens (Google/Apple client ids of the app).</p></div>';
    echo '<div class="form-group"><label>Pub/Sub Push Service Account (Google Play only)</label>'
        . '<input type="text" name="pubsub_service_account" class="form-control" value="'
        . ($isEdit ? htmlspecialchars($app['pubsub_service_account'] ?? '') : '') . '">'
        . '<p class="help-block">The service account email Google Pub/Sub authenticates its push (OIDC) with.</p></div>';
    echo '<div class="form-group"><label>Store Credentials (JSON)</label>'
        . '<textarea name="credentials" class="form-control" rows="4" placeholder="'
        . ($isEdit ? 'Stored. Paste a new value to replace; leave empty to keep.' : 'Google: service-account JSON. Apple: issuer id / key id / p8. Microsoft: AAD tenant / client / secret.')
        . '"></textarea>'
        . '<p class="help-block">Stored encrypted. Never displayed back.</p></div>';
    echo '<div class="form-group"><label>Status</label><select name="status" class="form-control">'
        . '<option value="active"' . ($isEdit && $app['status'] === 'active' ? ' selected' : '') . '>Active</option>'
        . '<option value="disabled"' . ($isEdit && $app['status'] === 'disabled' ? ' selected' : '') . '>Disabled</option>'
        . '</select></div>';
    echo '<button type="submit" class="btn btn-primary">Save</button>';
    echo '</form>';
}

/** Catalog tab: plan-granularity SKU mappings. */
function vpnhoodiap_renderCatalog(IapRepository $repo, string $modulelink): void
{
    $mappings = $repo->allProductMappings();
    echo '<table class="table table-striped"><thead><tr>'
        . '<th>App</th><th>Store</th><th>Store Product</th><th>Base Plan</th><th>WHMCS Product</th><th>Cycle (months)</th><th>Enabled</th><th></th></tr></thead><tbody>';
    if (empty($mappings)) {
        echo '<tr><td colspan="8" class="text-center text-muted">No catalog mappings. Purchases for unmapped SKUs are parked, never delivered.</td></tr>';
    }
    foreach ($mappings as $m) {
        // the app implies the store, but a package name alone does not show it — and per-store
        // catalogs are the whole point of mapping by app, so say it outright
        echo '<tr>'
            . '<td>#' . (int) $m['app_id'] . ' ' . htmlspecialchars($m['package_name'] ?? '') . '</td>'
            . '<td><code>' . htmlspecialchars($m['store'] ?? '') . '</code></td>'
            . '<td><code>' . htmlspecialchars($m['store_product_id']) . '</code></td>'
            . '<td><code>' . htmlspecialchars($m['store_base_plan_id']) . '</code></td>'
            . '<td>#' . (int) $m['whmcs_product_id'] . ' ' . htmlspecialchars($m['product_name'] ?? '') . '</td>'
            . '<td>' . ((int) $m['billing_cycle_months'] === 0 ? 'one-time' : (int) $m['billing_cycle_months']) . '</td>'
            . '<td>' . ($m['enabled'] ? 'Yes' : 'No') . '</td>'
            . '<td><form method="post" action="' . $modulelink . '&tab=catalog" style="margin:0">'
            . vpnhoodiap_csrfField()
            . '<input type="hidden" name="do" value="product_delete">'
            . '<input type="hidden" name="id" value="' . (int) $m['id'] . '">'
            . '<button class="btn btn-xs btn-danger">Delete</button></form></td>'
            . '</tr>';
    }
    echo '</tbody></table>';

    $apps = $repo->allApps();
    $products = $repo->whmcsProducts();
    echo '<hr><h4>Add Mapping</h4>';
    echo '<form method="post" action="' . $modulelink . '&tab=catalog" class="form-inline">';
    echo vpnhoodiap_csrfField();
    echo '<input type="hidden" name="do" value="product_save">';
    echo '<select name="app_id" class="form-control" required><option value="">— App —</option>';
    foreach ($apps as $a) {
        echo '<option value="' . (int) $a['id'] . '">#' . (int) $a['id'] . ' ' . htmlspecialchars($a['store'] . ' ' . $a['package_name']) . '</option>';
    }
    echo '</select> ';
    echo '<input type="text" name="store_product_id" class="form-control" placeholder="store product id" required> ';
    echo '<input type="text" name="store_base_plan_id" class="form-control" placeholder="base plan (google)"> ';
    echo '<select name="whmcs_product_id" class="form-control" required><option value="">— WHMCS product —</option>';
    foreach ($products as $p) {
        echo '<option value="' . (int) $p['id'] . '">#' . (int) $p['id'] . ' ' . htmlspecialchars($p['name']) . '</option>';
    }
    echo '</select> ';
    echo '<input type="number" name="billing_cycle_months" class="form-control" style="width:90px" value="1" min="0" title="months; 0 = one-time"> ';
    echo '<button class="btn btn-success">Add</button>';
    echo '</form>';
    echo '<p class="help-block">Mapping unit is the plan: (store product id, base plan) → (WHMCS product, cycle). A plan without a mapping is simply not sellable in that app.</p>';
}

/** Purchases tab (read-only, latest 50). */
function vpnhoodiap_renderPurchases(IapRepository $repo): void
{
    $rows = $repo->recentPurchases(50);
    echo '<table class="table table-condensed table-striped"><thead><tr>'
        . '<th>ID</th><th>Store</th><th>Order Id</th><th>Status</th><th>Client</th><th>Service</th>'
        . '<th title="what the buyer actually paid at the store — informational, never booked">Store Paid</th>'
        . '<th>Expiry</th><th>Updated</th></tr></thead><tbody>';
    if (empty($rows)) {
        echo '<tr><td colspan="9" class="text-center text-muted">No purchases recorded yet.</td></tr>';
    }
    foreach ($rows as $r) {
        $paid = $r['store_amount'] !== null && $r['store_amount'] !== ''
            ? htmlspecialchars($r['store_amount'] . ' ' . (string) $r['store_currency'])
            : '—';
        echo '<tr>'
            . '<td>' . (int) $r['id'] . '</td>'
            . '<td>' . htmlspecialchars($r['store']) . '</td>'
            . '<td><code>' . htmlspecialchars((string) $r['store_order_id']) . '</code></td>'
            . '<td>' . htmlspecialchars($r['status']) . '</td>'
            . '<td>' . ($r['client_id'] ? '<a href="clientssummary.php?userid=' . (int) $r['client_id'] . '">#' . (int) $r['client_id'] . '</a>' : '—') . '</td>'
            . '<td>' . ($r['service_id'] ? '#' . (int) $r['service_id'] : '—') . '</td>'
            . '<td>' . $paid . '</td>'
            . '<td>' . htmlspecialchars((string) $r['expiry_time']) . '</td>'
            . '<td>' . htmlspecialchars((string) $r['updated_at']) . '</td>'
            . '</tr>';
    }
    echo '</tbody></table>';
}

/** Events tab (read-only, latest 50). */
function vpnhoodiap_renderEvents(IapRepository $repo): void
{
    $rows = $repo->recentEvents(50);
    echo '<table class="table table-condensed table-striped"><thead><tr>'
        . '<th>ID</th><th>Store</th><th>Type</th><th>Status</th><th>Error</th><th>Event Time</th><th>Received</th></tr></thead><tbody>';
    if (empty($rows)) {
        echo '<tr><td colspan="7" class="text-center text-muted">No store notifications received yet.</td></tr>';
    }
    foreach ($rows as $r) {
        echo '<tr>'
            . '<td>' . (int) $r['id'] . '</td>'
            . '<td>' . htmlspecialchars($r['store']) . '</td>'
            . '<td>' . htmlspecialchars((string) $r['event_type']) . '</td>'
            . '<td>' . htmlspecialchars($r['status']) . '</td>'
            . '<td>' . htmlspecialchars((string) $r['error']) . '</td>'
            . '<td>' . htmlspecialchars((string) $r['event_time']) . '</td>'
            . '<td>' . htmlspecialchars((string) $r['created_at']) . '</td>'
            . '</tr>';
    }
    echo '</tbody></table>';
}

/** Log tab (read-only, latest 50). */
function vpnhoodiap_renderLog(IapRepository $repo): void
{
    $rows = $repo->recentLog(50);
    echo '<table class="table table-condensed table-striped"><thead><tr>'
        . '<th>ID</th><th>Action</th><th>IP</th><th>Status</th><th>Time</th></tr></thead><tbody>';
    if (empty($rows)) {
        echo '<tr><td colspan="5" class="text-center text-muted">No API calls logged yet.</td></tr>';
    }
    foreach ($rows as $r) {
        echo '<tr>'
            . '<td>' . (int) $r['id'] . '</td>'
            . '<td>' . htmlspecialchars((string) $r['action']) . '</td>'
            . '<td>' . htmlspecialchars((string) $r['remote_ip']) . '</td>'
            . '<td>' . (int) $r['http_status'] . '</td>'
            . '<td>' . htmlspecialchars((string) $r['created_at']) . '</td>'
            . '</tr>';
    }
    echo '</tbody></table>';
}
