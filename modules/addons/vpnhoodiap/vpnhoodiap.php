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
        'version'     => '1.8.12',
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
            'RequestLogRetentionDays' => [
                'FriendlyName' => 'Request Log Retention (days)',
                'Type'         => 'text',
                'Size'         => '6',
                'Description'  => 'The API request log holds each caller\'s IP address. Enter the days your privacy policy promises: the daily cleanup deletes rows a day early, so none outlives them.',
                'Default'      => '30',
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
 * Create database tables on activation, each in its current shape. Only creates tables
 * that do not exist, so deactivate/reactivate cycles are harmless.
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
                $table->string('store', 16); // googleplay | appstore | microsoft | web (no store)
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
                // sellable=0: redemption-only — purchases on the SKU still map
                // (redeem + renewals) but the app no longer offers it for sale
                $table->boolean('sellable')->default(true);
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
                // the one code the person typed in, kept as typed: whether it works is the access
                // server's answer at use, never decided when it is saved
                $table->string('uploaded_access_code', 64)->nullable();
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

        // opaque app session tokens, hashed at rest; no time limit, and a sign-out deletes one
        if (!$schema->hasTable('mod_vpnhood_iap_sessions')) {
            $schema->create('mod_vpnhood_iap_sessions', function ($table) {
                $table->increments('id');
                $table->integer('user_id')->unsigned()->index();
                $table->string('token_hash', 64)->unique();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
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
                // gateway agreement references and other non-personal breadcrumbs the deletion must keep
                $table->text('details')->nullable();
            });
        }

        // an account's hold on a key it proved by pasting the code (lifecycle §7/§8); nothing
        // about billing moves. A pure web customer has no module account, so a claim is keyed
        // by the module user or by the WHMCS client. is_auto_selectable off: the ranking never
        // picks that code (one bought to give away).
        if (!$schema->hasTable('mod_vpnhood_iap_claims')) {
            $schema->create('mod_vpnhood_iap_claims', function ($table) {
                $table->increments('id');
                $table->integer('user_id')->unsigned()->nullable()->index();
                $table->integer('client_id')->unsigned()->nullable()->index();
                $table->integer('service_id')->unsigned()->index();
                $table->boolean('is_default')->default(false);
                $table->timestamp('created_at')->nullable();
                $table->boolean('is_auto_selectable')->default(true);
                $table->unique(['user_id', 'service_id'], 'iap_claims_user_service');
                $table->unique(['client_id', 'service_id'], 'iap_claims_client_service');
            });
        }

        // a device met the access server's refusal of this code. Identical codes are the same
        // credential, so a row is keyed by (account, code fingerprint), and only the fingerprint
        // is stored. A repeat refusal re-inserts the row: the id orders the refusals, so once
        // every code of an account is refused they take turns, least recently refused first.
        if (!$schema->hasTable('mod_vpnhood_iap_code_rejections')) {
            $schema->create('mod_vpnhood_iap_code_rejections', function ($table) {
                $table->increments('id');
                $table->integer('user_id')->unsigned()->index();
                $table->string('code_hash', 64);
                $table->timestamp('refused_at')->nullable();
                $table->unique(['user_id', 'code_hash'], 'iap_code_rejections_user_code');
            });
        }

        // the password grant: the second-factor tokens the password form hands out
        // (single-use, minutes-long, an attempt budget; never sessions)
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

        // failed password sign-ins per address, for the cooldown. Only a sha256 of the normalized
        // address: a nonexistent one cools down exactly like a real one, or the cooldown would
        // betray which addresses exist.
        if (!$schema->hasTable('mod_vpnhood_iap_login_attempts')) {
            $schema->create('mod_vpnhood_iap_login_attempts', function ($table) {
                $table->increments('id');
                $table->char('email_hash', 64)->index();
                $table->timestamp('created_at')->nullable()->index();
            });
        }

        // zero-tap sign-in restoration: one row per device-held WebAuthn key, with the client
        // origin captured at registration (an assertion from a differently-signed app is refused)
        // and the sign count (stored, never enforced: restores reset it)
        if (!$schema->hasTable('mod_vpnhood_iap_restore_credentials')) {
            $schema->create('mod_vpnhood_iap_restore_credentials', function ($table) {
                $table->increments('id');
                $table->integer('user_id')->unsigned()->index();
                $table->string('credential_id', 191)->unique(); // base64url, the device's handle
                $table->text('public_key_pem');
                $table->string('origin', 191);
                $table->integer('sign_count')->unsigned()->default(0);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
            });
        }

        // the single-use nonces both restore ceremonies answer: hashed, minutes-long, and bound
        // to a purpose, so a registration challenge can never sign anybody in
        if (!$schema->hasTable('mod_vpnhood_iap_restore_challenges')) {
            $schema->create('mod_vpnhood_iap_restore_challenges', function ($table) {
                $table->increments('id');
                $table->string('purpose', 16); // register | assert
                $table->char('challenge_hash', 64)->index();
                $table->integer('user_id')->unsigned()->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('used_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        // TEMPORARY, removed with LegacyStoreHandover: a previous store's Play subscriptions still
        // auto-renewing, loaded by a one-shot import and read once per sign-in, so a returning
        // customer gets their code without asking. Keyed on the address: the old store never
        // stored a Google OIDC subject (LegacyStoreHandover says why that is safe).
        if (!$schema->hasTable('mod_vpnhood_iap_legacy_subs')) {
            $schema->create('mod_vpnhood_iap_legacy_subs', function ($table) {
                $table->increments('id');
                $table->string('store', 32)->default('googleplay');
                $table->string('package_name');
                $table->string('store_product_id');
                $table->string('store_base_plan_id')->default('');
                // the store's own purchase token: what gets re-verified at claim time
                $table->string('purchase_key', 255);
                $table->string('provider_order_id')->nullable();
                // the OLD store's obfuscated uid - kept for tracing, never matched on
                $table->string('obfuscated_uid', 64)->nullable();
                $table->string('email')->index();      // normalized lower-case
                $table->timestamp('expires_at')->nullable();
                $table->boolean('is_auto_renew')->default(true);
                $table->decimal('price_amount', 12, 2)->nullable();
                $table->string('price_currency', 8)->nullable();
                // pending -> claimed (handed over) | inactive (the store closed it out)
                $table->string('status', 16)->default('pending')->index();
                $table->integer('claimed_user_id')->unsigned()->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->integer('attempts')->unsigned()->default(0);
                $table->timestamp('last_attempt_at')->nullable();
                $table->string('last_error', 500)->nullable();
                $table->timestamp('imported_at')->nullable();
                // re-importing a refreshed export must update, never duplicate
                $table->unique(['store', 'purchase_key'], 'iap_legacy_store_purchase');
            });
        }

        vpnhoodiap_ensureAdminAccess();
        vpnhoodiap_ensureIapGateway();
        vpnhoodiap_hideGatewayFromCheckout();
        vpnhoodiap_keepInvoiceSnapshotsOn();

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
 * Invoked by WHMCS when the version in vpnhoodiap_config() increases. No migrations: until a
 * partner install runs this module, a change an existing install needs is made by hand, and
 * vpnhoodiap_activate() creates every table in its current shape. An upgrade re-runs
 * activation's idempotent self-heals.
 */
function vpnhoodiap_upgrade(array $vars): void
{
    vpnhoodiap_ensureAdminAccess();
    vpnhoodiap_ensureIapGateway();
    vpnhoodiap_hideGatewayFromCheckout();
}

/**
 * Register the bookkeeping gateway (modules/gateways/vpnhoodiappay.php) if nobody has activated it
 * in admin — an in-app purchase must never depend on a manual activation step. The rows are the
 * ones WHMCS activation writes for a config-less Invoices gateway; existing values are never
 * touched (the visible clamp below is the one exception, and it exists already).
 */
function vpnhoodiap_ensureIapGateway(): void
{
    $existing = Capsule::table('tblpaymentgateways')
        ->where('gateway', \WHMCS\Module\Addon\VpnHoodIap\Provisioning\OrderProvisioner::GATEWAY)
        ->pluck('setting')->all();
    $wanted = [
        'name'      => 'In-App Purchase',
        'type'      => 'Invoices',
        'convertto' => '',
        'visible'   => '',
    ];
    foreach ($wanted as $setting => $value) {
        if (!in_array($setting, $existing, true)) {
            Capsule::table('tblpaymentgateways')->insert([
                'gateway' => \WHMCS\Module\Addon\VpnHoodIap\Provisioning\OrderProvisioner::GATEWAY,
                'setting' => $setting,
                'value'   => $value,
                'order'   => 0,
            ]);
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
 * Account deletion keeps each invoice's buyer as issued through WHMCS's own client data
 * snapshot, which WHMCS honours only while "Store Client Data Snapshot" is on (lifecycle
 * §5). Turned on at activation; the cron hook and every deletion keep it on.
 */
function vpnhoodiap_keepInvoiceSnapshotsOn(): void
{
    require_once __DIR__ . '/lib/ApiException.php';
    require_once __DIR__ . '/lib/Provisioning/AccountDeletionService.php';
    \WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountDeletionService::keepInvoiceSnapshotsOn();
}

/**
 * WHMCS lists an addon under the Addons menu only for the admin roles named in its
 * `access` setting, and only the admin-UI activation flow writes that row. Activate
 * the module any other way — the API, an automated install, a test harness — and it
 * is installed, fully working, and completely invisible, with nothing to indicate
 * why. Granting it here makes activation self-sufficient however it was triggered.
 *
 * One of the module's few writes to a WHMCS core table (CLAUDE.md lists them): `access`
 * lives in tbladdonmodules alongside the module's own settings, and no localAPI command
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
    // a pure web customer has no module account; a stand-in row keys their
    // claims by client_id instead (the same pattern as the deletion page)
    $keyUser = ($clientId > 0 ? $repo->findUserByClient($clientId) : null) ?? ['id' => 0, 'client_id' => $clientId];
    $keyService = new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountKeyService($repo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $clientId > 0) {
        try {
            $token = (string) ($_POST['token'] ?? '');
            if ($token === '' || !hash_equals((string) $_SESSION['vpnhoodiap_ca_csrf'], $token)) {
                throw new \RuntimeException('Invalid or expired security token. Please reload the page and try again.');
            }
            $do = (string) ($_POST['do'] ?? '');
            if ($do === 'auto-select') {
                // The panel's one inventory control. Reversible, and it deletes
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
                // whether the code works.
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
                // The client-area half of Retry: put a rejected code back in the
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
            // No "active" badge: there is no stored selection to show. What each
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
            (new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountDeletionService())->deleteClient($clientId);
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
            $bulkOrders = (new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountKeyService($repo))
                ->bulkOrderCount($repo->findUserByClient($clientId) ?? ['id' => 0, 'client_id' => $clientId]);
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
 * Accounts (deletion on request), Purchases / Events / Log (read-only monitors).
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
                    'sellable'             => isset($_POST['sellable']) ? 1 : 0,
                ]);
                $notice = 'Catalog mapping added.';
                $noticeType = 'success';
                $tab = 'catalog';
            } elseif ($sub === 'product_delete') {
                $repo->deleteProductMapping((int) $_POST['id']);
                $notice = 'Catalog mapping removed.';
                $noticeType = 'success';
                $tab = 'catalog';
            } elseif ($sub === 'product_sellable') {
                $repo->setProductSellable((int) $_POST['id'], (int) ($_POST['sellable'] ?? 1) === 1);
                $notice = 'Catalog mapping updated.';
                $noticeType = 'success';
                $tab = 'catalog';
            } elseif ($sub === 'account_delete') {
                $tab = 'accounts';
                if (($_POST['confirm'] ?? '') !== 'yes') {
                    throw new \RuntimeException('Tick the confirmation box to delete the account.');
                }
                $user = $repo->getUser((int) ($_POST['id'] ?? 0));
                if ($user === null) {
                    throw new \RuntimeException('That account no longer exists.');
                }
                require_once __DIR__ . '/lib/ApiException.php';
                require_once __DIR__ . '/lib/Provisioning/AccountDeletionService.php';
                (new \WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountDeletionService())->deleteUser($user);
                $notice = 'Account #' . (int) $user['id'] . ' is deleted.';
                $noticeType = 'success';
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
    $tabs = ['apps' => 'Apps', 'catalog' => 'Catalog', 'accounts' => 'Accounts', 'purchases' => 'Purchases',
        'events' => 'Events', 'log' => 'Log'];
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
        case 'accounts':
            vpnhoodiap_renderAccounts($repo, $modulelink);
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
        . '<p class="help-block">Android package name, Apple bundle id, or Microsoft Store id. Webhook and sign-in requests are matched to this app by it. '
        . 'Pick store <code>web</code> for a direct-download build (own website, sideloaded APK): it registers the package so it can sign in, and needs no credentials — nothing can be purchased through it.</p></div>';
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
        . '<th>App</th><th>Store</th><th>Store Product</th><th>Base Plan</th><th>WHMCS Product</th><th>Cycle (months)</th><th>Enabled</th><th>Sellable</th><th></th></tr></thead><tbody>';
    if (empty($mappings)) {
        echo '<tr><td colspan="9" class="text-center text-muted">No catalog mappings. Purchases for unmapped SKUs are parked, never delivered.</td></tr>';
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
            . '<td>' . ($m['sellable'] ? 'Yes' : 'No')
            . ' <form method="post" action="' . $modulelink . '&tab=catalog" style="margin:0;display:inline">'
            . vpnhoodiap_csrfField()
            . '<input type="hidden" name="do" value="product_sellable">'
            . '<input type="hidden" name="id" value="' . (int) $m['id'] . '">'
            . '<input type="hidden" name="sellable" value="' . ($m['sellable'] ? 0 : 1) . '">'
            . '<button class="btn btn-xs btn-default" title="sellable=No keeps redeeming old purchases; the app just stops offering the plan">'
            . ($m['sellable'] ? 'Hide from app' : 'Offer in app') . '</button></form></td>'
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
    echo '<label class="checkbox-inline" title="untick for a retired SKU that must still redeem"><input type="checkbox" name="sellable" checked> sellable</label> ';
    echo '<button class="btn btn-success">Add</button>';
    echo '</form>';
    echo '<p class="help-block">Mapping unit is the plan: (store product id, base plan) → (WHMCS product, cycle). A plan without a mapping is simply not sellable in that app.</p>';
}

/**
 * Accounts tab: deletion on request, for a person who can delete neither in the app nor on
 * the website (signed in only in the app, or with an address WHMCS never saw). The request
 * comes from an address the account signs in with, so that is what finds it: never the
 * address on the account row, a snapshot that goes stale.
 */
function vpnhoodiap_renderAccounts(IapRepository $repo, string $modulelink): void
{
    $email = trim((string) ($_REQUEST['email'] ?? ''));
    echo '<form method="get" action="addonmodules.php" class="form-inline" style="margin-bottom:12px">'
        . '<input type="hidden" name="module" value="vpnhoodiap"><input type="hidden" name="tab" value="accounts">'
        . '<input type="email" name="email" class="form-control" style="width:320px" required'
        . ' placeholder="the address the request came from" value="' . htmlspecialchars($email) . '"> '
        . '<button class="btn btn-default">Find</button></form>';
    if ($email === '') {
        echo '<p class="help-block">A person who cannot delete their account in the app or on the website asks for it '
            . 'from the address they sign in with. Find the account by that address and delete it here: the same '
            . 'deletion the app and the website run.</p>';
        return;
    }

    $users = $repo->findUsersByIdentityEmail($email);
    if ($users === []) {
        echo '<p class="text-muted">No account signs in with that address.</p>';
        return;
    }
    echo '<table class="table table-condensed table-striped"><thead><tr>'
        . '<th>Account</th><th>Signs in with</th><th>Website client</th><th>Created</th><th></th></tr></thead><tbody>';
    foreach ($users as $user) {
        $methods = array_map(fn (array $identity) => htmlspecialchars($identity['provider'] . ' (' . $identity['email'] . ')'),
            $repo->identitiesForUser((int) $user['id']));
        $clientId = (int) ($user['client_id'] ?? 0);
        echo '<tr><td>#' . (int) $user['id'] . '</td>'
            . '<td>' . implode(', ', $methods) . '</td>'
            . '<td>' . ($clientId > 0 ? '<a href="clientssummary.php?userid=' . $clientId . '">#' . $clientId . '</a>' : '—') . '</td>'
            . '<td>' . htmlspecialchars((string) $user['created_at']) . '</td>'
            . '<td><form method="post" action="' . $modulelink . '&tab=accounts" style="margin:0">'
            . vpnhoodiap_csrfField()
            . '<input type="hidden" name="do" value="account_delete">'
            . '<input type="hidden" name="id" value="' . (int) $user['id'] . '">'
            . '<label class="checkbox-inline"><input type="checkbox" name="confirm" value="yes"> permanently</label> '
            . '<button class="btn btn-xs btn-danger">Delete</button></form></td></tr>';
    }
    echo '</tbody></table>';
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
