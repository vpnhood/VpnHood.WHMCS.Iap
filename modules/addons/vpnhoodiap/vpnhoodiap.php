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
        'version'     => '1.0.7',
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
                // THE ACCOUNT IS THE PERSON, one per verified email address. Sign-in
                // proofs live in mod_vpnhood_iap_identities (several per account):
                // a known (provider, subject) always wins, a new provider proving a
                // known address joins that account. provider/provider_subject here
                // only mirror the most recent sign-in for the admin's benefit.
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
                $table->unique('email');
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

        vpnhoodiap_ensureAdminAccess();

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
 * Migration point for future versions (invoked by WHMCS when the version in
 * vpnhoodiap_config() increases). Keep migrations additive and idempotent.
 */
function vpnhoodiap_upgrade(array $vars): void
{
    // installs activated before this ran (API/automation) have no access row and
    // are invisible in the Addons menu until someone notices
    vpnhoodiap_ensureAdminAccess();

    vpnhoodiap_migrateToEmailIdentity();
    vpnhoodiap_migrateToLinkedIdentities();
    vpnhoodiap_migrateToDisplayName();
    vpnhoodiap_migrateOffEmailVerificationParking();
    vpnhoodiap_migrateToClientAreaVerificationGate();
    vpnhoodiap_migrateToDeletionJournal();
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
 * Re-key users on the email (see IapRepository::findOrCreateUser). Installs created
 * before this shipped are keyed on (provider, provider_subject), so the same person
 * signing in with a second provider would have received a second account.
 *
 * Emails are lowercased first, then the unique index moves. If two rows already share
 * an address the index cannot be applied — that is a genuine split account whose
 * purchases must be merged by hand, so it is reported loudly rather than papered over;
 * lookups stay deterministic (oldest row) until it is resolved.
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
        logActivity('vpnhoodiap: cannot key accounts by email — these addresses have more than one user row and must be merged manually: '
            . implode(', ', array_slice($duplicates, 0, 20)));
        return;
    }

    try {
        $schema->table('mod_vpnhood_iap_users', function ($table) {
            $table->unique('email');
        });
    } catch (\Throwable $e) {
        // already unique on a re-run — the index is the goal, not the attempt
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
 * Client area output — two pages: the email-confirmation gate (default) and the
 * account-deletion page (`action=delete-account`, the web deletion path Google
 * Play requires). Both pass the verify-gate hook, which allows every
 * `m=vpnhoodiap` page.
 */
function vpnhoodiap_clientarea(array $vars): array
{
    $action = (string) ($_REQUEST['action'] ?? '');
    return $action === 'delete-account'
        ? vpnhoodiap_clientareaDeleteAccount()
        : vpnhoodiap_clientareaVerifyEmail();
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

    return [
        'pagetitle'    => 'Delete my account',
        'breadcrumb'   => ['index.php?m=vpnhoodiap&action=delete-account' => 'Delete my account'],
        'templatefile' => 'delete-account',
        'requirelogin' => true,
        'vars'         => [
            'email' => $email,
            'error' => $error,
            'csrf'  => $_SESSION['vpnhoodiap_ca_csrf'],
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
        . '<th>App</th><th>Store Product</th><th>Base Plan</th><th>WHMCS Product</th><th>Cycle (months)</th><th>Enabled</th><th></th></tr></thead><tbody>';
    if (empty($mappings)) {
        echo '<tr><td colspan="7" class="text-center text-muted">No catalog mappings. Purchases for unmapped SKUs are parked, never delivered.</td></tr>';
    }
    foreach ($mappings as $m) {
        echo '<tr>'
            . '<td>#' . (int) $m['app_id'] . ' ' . htmlspecialchars($m['package_name'] ?? '') . '</td>'
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
