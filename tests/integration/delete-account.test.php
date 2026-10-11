<?php
/**
 * delete-account.test.php — account deletion inside the deployed dev WHMCS.
 *
 * Covers the whole contract: the person is erased everywhere, WHMCS's own traces
 * of them included (mail log, activity log, sign-in and order addresses, custom
 * fields, tickets, sign-in provider links, every login, the one without a link to
 * the client too), the
 * paid service and the invoice history are NOT, an active web service no longer
 * blocks — its billing is cancelled at the END of the paid period and journalled
 * with the agreement reference — every invoice keeps the buyer's real name and
 * address in WHMCS's own snapshot, taken before the client row is anonymized when
 * the invoice had none, and stripped of what no invoice shows, a
 * re-run finishes a close a failed run left undone, the web deletion takes the
 * client's own app accounts and no other, the real DELETE /account endpoint does
 * all of it over HTTP with a session token, and the admin's Accounts tab deletes
 * on request an account it finds by the address it signs in with. There is deliberately NO deletion-preview endpoint: the
 * confirmation screen warns without listing; nothing is mailed on the way out.
 *
 * Writes go through localAPI (clients, logins, orders, services, tickets) or the
 * module's own mod_vpnhood_iap_* tables. The fixtures no localAPI command can make
 * are written raw — a login's last IP and its link to the client, a client custom
 * field, a mail-log row, activity rows, a sign-in provider link — and cleanup
 * removes them, with this test's own cancellation-request rows. No mail leaves it.
 */

require __DIR__ . '/lib/common.php';

requireIapLib('ApiException.php', 'Auth/SessionService.php', 'Provisioning/AccountDeletionService.php');

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\ApiException;
use WHMCS\Module\Addon\VpnHoodIap\Auth\SessionService;
use WHMCS\Module\Addon\VpnHoodIap\Provisioning\AccountDeletionService;

if (!iapModuleActive($db)) {
    bad('addon not active — run the activation test first');
    finish();
}
if (!tableExists($db, 'mod_vpnhood_iap_deletions')) {
    bad('mod_vpnhood_iap_deletions missing — run the activation test first');
    finish();
}

const API_URL = 'https://whmcs-dev.vpnhood.com/modules/addons/vpnhoodiap/api.php/v1';

$marker = 'deltest-' . bin2hex(random_bytes(4));
$clientIds = [];
$userIds = [];
$orderIds = [];
$loginIds = [];
$customFieldId = 0;
$legacyTable = tableExists($db, 'mod_vpnhood_iap_legacy_subs');

/** A throwaway WHMCS client. */
function makeClient(string $email): int
{
    $result = localAPI('AddClient', [
        'firstname'      => 'Delete',
        'lastname'       => 'Test',
        'email'          => $email,
        'password2'      => bin2hex(random_bytes(12)),
        'companyname'    => 'Delete Test Co',
        'address2'       => 'Suite 7',
        'country'        => 'US',
        'tax_id'         => 'TAX-7',
        'notes'          => 'a note about the person',
        'phonenumber'    => '5550100',
        'clientip'       => '203.0.113.7',
        'skipvalidation' => true,
        'noemail'        => true,
    ]);
    if (($result['result'] ?? '') !== 'success') {
        throw new RuntimeException('AddClient failed: ' . json_encode($result));
    }
    return (int) $result['clientid'];
}

/** A module account, optionally attached to a WHMCS client. */
function makeUser(string $marker, ?int $clientId): int
{
    return (int) Capsule::table('mod_vpnhood_iap_users')->insertGetId([
        'provider'             => 'google',
        'provider_subject'     => $marker,
        'email'                => "$marker@vpnhood.test",
        'email_verified_claim' => 1,
        'client_id'            => $clientId,
        'external_uid'         => sprintf('%s-0000-4000-8000-%s', substr(md5($marker), 0, 8), substr(md5($marker), 0, 12)),
        'created_at'           => date('Y-m-d H:i:s'),
        'updated_at'           => date('Y-m-d H:i:s'),
    ]);
}

function userRow(int $userId): ?array
{
    $row = Capsule::table('mod_vpnhood_iap_users')->where('id', $userId)->first();
    return $row === null ? null : (array) $row;
}

/** A row's column. A missing one fails the run: read as empty, it would pass for erased. */
function column(array $row, string $name): string
{
    if (!array_key_exists($name, $row)) {
        throw new RuntimeException("column $name is missing: WHMCS renamed it, and the deletion no longer erases it");
    }
    return (string) $row[$name];
}

/**
 * What WHMCS keeps of a person, on top of what AddClient leaves: a sign-in address on each
 * login, a custom field value, a mail-log row, an activity row filed under the first login
 * alone, one filed under nobody that quotes an address and one per login that names it by
 * id and the person by name, a ticket with a reply, a ticket sent from the address without
 * signing in, and a link to a sign-in provider.
 *
 * @param int[] $loginIds
 * @return int[] the ticket ids
 */
function addTraces(string $marker, int $clientId, array $loginIds, string $email, string $quotedEmail,
    int $customFieldId): array
{
    $now = date('Y-m-d H:i:s');
    Capsule::table('tblusers')->whereIn('id', $loginIds)
        ->update(['last_ip' => '203.0.113.8', 'last_hostname' => 'deltest.example']);
    localAPI('UpdateClient', [
        'clientid'       => $clientId,
        'customfields'   => base64_encode(serialize([$customFieldId => 'something about the person'])),
        'skipvalidation' => true,
    ]);
    if ((string) Capsule::table('tblcustomfieldsvalues')->where('fieldid', $customFieldId)->where('relid', $clientId)->value('value') === '') {
        throw new RuntimeException('the custom field fixture did not take');
    }
    Capsule::table('tblemails')->insert([
        'userid'     => $clientId,
        'subject'    => "mail to $marker",
        'message'    => 'a message to the person',
        'date'       => $now,
        'to'         => $email,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    Capsule::table('tblactivitylog')->insert([
        ['date' => $now, 'description' => "$marker: a sign-in", 'user' => 'Client', 'userid' => 0,
            'user_id' => $loginIds[0], 'admin_id' => 0, 'ipaddr' => '203.0.113.8'],
        ['date' => $now, 'description' => "Email changed from $quotedEmail.", 'user' => 'System', 'userid' => 0,
            'user_id' => 0, 'admin_id' => 0, 'ipaddr' => '203.0.113.8'],
    ]);
    foreach ($loginIds as $loginId) {
        Capsule::table('tblactivitylog')->insert(['date' => $now,
            'description' => "Email Sent to $marker Person (Email Address Verification) - UserID: $loginId",
            'user' => 'System', 'userid' => 0, 'user_id' => 0, 'admin_id' => 0, 'ipaddr' => '']);
    }

    $departmentId = (int) Capsule::table('tblticketdepartments')->orderBy('id')->value('id');
    $ticket = localAPI('OpenTicket', [
        'deptid'   => $departmentId,
        'clientid' => $clientId,
        'subject'  => "help $marker",
        'message'  => 'a question from the person',
        'noemail'  => true,
    ]);
    $sentWithoutSigningIn = localAPI('OpenTicket', [
        'deptid'  => $departmentId,
        'name'    => 'Delete Test',
        'email'   => $email,
        'subject' => "guest $marker",
        'message' => 'a question sent without signing in',
        'noemail' => true,
    ]);
    $ticketIds = [(int) ($ticket['id'] ?? 0), (int) ($sentWithoutSigningIn['id'] ?? 0)];
    $reply = localAPI('AddTicketReply', [
        'ticketid' => $ticketIds[0],
        'clientid' => $clientId,
        'message'  => 'more about it',
        'noemail'  => true,
    ]);
    if (in_array(0, $ticketIds, true) || ($reply['result'] ?? '') !== 'success') {
        throw new RuntimeException('ticket fixtures failed: ' . json_encode([$ticket, $sentWithoutSigningIn, $reply]));
    }

    Capsule::table('tblauthn_account_links')->insert([
        'provider'       => 'google',
        'remote_user_id' => "$marker-remote",
        'user_id'        => $loginIds[0],
        'client_id'      => $clientId,
        'metadata'       => json_encode(['email' => $email]),
        'created_at'     => $now,
        'updated_at'     => $now,
    ]);
    return $ticketIds;
}

/**
 * Nothing addTraces() left survives, nor the person's details on the client record.
 *
 * @param int[] $loginIds
 * @param string[] $addresses the person's real addresses
 * @param int[] $ticketIds
 */
function assertTracesErased(string $by, int $clientId, array $loginIds, array $addresses, int $customFieldId, array $ticketIds): void
{
    foreach ($loginIds as $id) {
        $login = (array) Capsule::table('tblusers')->where('id', $id)->first();
        ($login !== [] && str_ends_with(column($login, 'email'), '@anonymized.invalid')
            && column($login, 'last_ip') === '' && column($login, 'last_hostname') === '')
            ? ok("$by: login #$id is anonymized, its last sign-in address cleared")
            : bad("$by: login #$id survived: " . json_encode(['email' => $login['email'] ?? null, 'lastIp' => $login['last_ip'] ?? null]));
    }
    (Capsule::table('tblactivitylog')->whereIn('user_id', $loginIds)->count() === 0
        && Capsule::table('tblactivitylog')->where('userid', $clientId)->count() === 0)
        ? ok("$by: the activity log holds nothing for the client or its logins, the one without a link included")
        : bad("$by: activity-log rows of the client or a login survived");
    $quoting = Capsule::table('tblactivitylog')->where(function ($query) use ($addresses) {
        foreach ($addresses as $address) {
            $query->orWhereRaw('INSTR(description, ?) > 0', [$address]);
        }
    })->get(['id', 'userid', 'user_id', 'description'])
        // whole addresses only: one inside a longer address is someone else's
        ->filter(fn ($row) => array_filter($addresses, fn (string $address) => preg_match(
            '/(?<![\w.%+-])' . preg_quote($address, '/') . '(?![\w%+-]|\.\w)/iu', (string) $row->description) === 1) !== [])
        ->values()->all();
    $quoting === []
        ? ok("$by: no activity row quotes the person's addresses, whoever it is filed under")
        : bad("$by: activity rows still quote the person's addresses: " . json_encode($quoting));
    $namingLogins = array_merge(...array_map(fn (int $id) => Capsule::table('tblactivitylog')
        ->where('userid', 0)->where('user_id', 0)->where('description', 'like', "%- UserID: $id")
        ->get(['id', 'description'])->all(), $loginIds));
    $namingLogins === []
        ? ok("$by: no row filed under nobody names one of the person's logins")
        : bad("$by: rows filed under nobody still name the person's logins: " . json_encode($namingLogins));
    Capsule::table('tblemails')->where('userid', $clientId)->count() === 0
        ? ok("$by: the mail log holds nothing for the client")
        : bad("$by: mail-log rows survived");
    $customValue = (string) Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', $customFieldId)->where('relid', $clientId)->value('value');
    $customValue === ''
        ? ok("$by: the client custom field value is blanked")
        : bad("$by: the client custom field value survived: $customValue");
    (Capsule::table('tbltickets')->whereIn('id', $ticketIds)->count() === 0
        && Capsule::table('tblticketreplies')->whereIn('tid', $ticketIds)->count() === 0)
        ? ok("$by: the person's tickets are gone with their replies (#" . implode(', #', $ticketIds) . ')')
        : bad("$by: a ticket or a reply survived");
    Capsule::table('tblauthn_account_links')->where('client_id', $clientId)->orWhereIn('user_id', $loginIds)->count() === 0
        ? ok("$by: no sign-in provider link is left")
        : bad("$by: a sign-in provider link survived");

    $client = (array) Capsule::table('tblclients')->where('id', $clientId)->first();
    $clearedFields = ['companyname', 'address2', 'tax_id', 'notes', 'ip', 'host'];
    $leftovers = array_filter(
        array_combine($clearedFields, array_map(fn (string $field) => column($client, $field), $clearedFields)),
        fn (string $value) => $value !== '');
    $leftovers === []
        ? ok("$by: company, address line 2, tax id, notes and the sign-in address are cleared")
        : bad("$by: the customer record keeps: " . json_encode($leftovers));
}

// no mail leaves this test: a new ticket would notify the department's admins
add_hook('EmailPreSend', 0, fn () => ['abortsend' => true]);

try {
    // == 1. the full erasure ==================================================
    $clientIds['main'] = makeClient("$marker@vpnhood.test");
    $userIds['main'] = makeUser($marker, $clientIds['main']);
    ok("fixture client #{$clientIds['main']} + module user #{$userIds['main']} created");

    Capsule::table('mod_vpnhood_iap_identities')->insert([
        'user_id'    => $userIds['main'],
        'provider'   => 'google',
        'provider_subject' => $marker,
        'email'      => "$marker@vpnhood.test",
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $sessions = new SessionService();
    $tokenA = $sessions->issue($userIds['main'])['token'];
    $tokenB = $sessions->issue($userIds['main'])['token'];

    // a provisioned purchase: it must survive the deletion, minus its owner
    $purchaseId = (int) Capsule::table('mod_vpnhood_iap_purchases')->insertGetId([
        'app_id'       => 0,
        'store'        => 'googleplay',
        'purchase_key' => "$marker-purchase",
        'user_id'      => $userIds['main'],
        'client_id'    => $clientIds['main'],
        'status'       => 'provisioned',
        'expiry_time'  => date('Y-m-d H:i:s', time() + 30 * 86400),
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    $email = "$marker@vpnhood.test";
    $secondEmail = "$marker-second@vpnhood.test";
    $now = date('Y-m-d H:i:s');

    // the owner login loses its link, so only its address finds it, and the address is
    // what the deletion rewrites; a second login holds the client through its link
    $loginId = (int) Capsule::table('tblusers')->where('email', $email)->value('id');
    $second = localAPI('AddUser', [
        'firstname' => 'Second',
        'lastname'  => 'Login',
        'email'     => $secondEmail,
        'password2' => bin2hex(random_bytes(12)),
    ]);
    $secondLoginId = (int) ($second['user_id'] ?? 0);
    if ($loginId <= 0 || $secondLoginId <= 0) {
        throw new RuntimeException('login fixtures failed: ' . json_encode(['owner' => $loginId, 'second' => $second]));
    }
    $loginIds = [$loginId, $secondLoginId];
    Capsule::table('tblusers_clients')->where('auth_user_id', $loginId)->delete();
    Capsule::table('tblusers_clients')->insert([
        'auth_user_id' => $secondLoginId,
        'client_id'    => $clientIds['main'],
        'owner'        => 0,
        'permissions'  => '',
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);

    // a client custom field holding something about the person; admin-only, so no form shows it
    $customFieldId = (int) Capsule::table('tblcustomfields')->insertGetId([
        'type'         => 'client',
        'relid'        => 0,
        'fieldname'    => $marker,
        'fieldtype'    => 'text',
        'description'  => '',
        'fieldoptions' => '',
        'regexpr'      => '',
        'adminonly'    => 'on',
        'required'     => '',
        'showorder'    => '',
        'showinvoice'  => '',
        'sortorder'    => 0,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
    // the row filed under nobody quotes the second login's address, which only that login gives away
    $ticketIds = addTraces($marker, $clientIds['main'], $loginIds, $email, $secondEmail, $customFieldId);
    if ($legacyTable) {
        // an old-store subscription this account claimed at sign-in
        Capsule::table('mod_vpnhood_iap_legacy_subs')->insert([
            'package_name'     => 'deltest',
            'store_product_id' => 'deltest',
            'purchase_key'     => "$marker-legacy",
            'email'            => $email,
            'status'           => 'claimed',
            'claimed_user_id'  => $userIds['main'],
            'claimed_at'       => $now,
        ]);
    }

    // someone else's address, with the person's inside it: not the person's, so it stays
    $longerAddressRowId = (int) Capsule::table('tblactivitylog')->insertGetId(['date' => $now,
        'description' => "Email changed from jo$email", 'user' => 'System', 'userid' => 0, 'user_id' => 0,
        'admin_id' => 0, 'ipaddr' => '203.0.113.10']);
    // another login, whose id ends in the person's
    $longerLoginRowId = (int) Capsule::table('tblactivitylog')->insertGetId(['date' => $now,
        'description' => "Email Sent to $marker Other (Email Address Verification) - UserID: 1$loginId",
        'user' => 'System', 'userid' => 0, 'user_id' => 0, 'admin_id' => 0, 'ipaddr' => '']);

    $mailLogBefore = Capsule::table('tblemails')->where('userid', $clientIds['main'])->count();
    $activityBefore = Capsule::table('tblactivitylog')->where('userid', $clientIds['main'])->count();
    ok("before deletion: logins #$loginId (no link) and #$secondLoginId, $mailLogBefore mail-log row(s), "
        . "$activityBefore activity-log row(s), tickets #" . implode(', #', $ticketIds));
    $lastLogIdBefore = (int) Capsule::table('tblactivitylog')->max('id');

    (new AccountDeletionService())->deleteUser(userRow($userIds['main']));

    assertTracesErased('the deletion', $clientIds['main'], $loginIds, [$email, $secondEmail], $customFieldId, $ticketIds);
    Capsule::table('tblactivitylog')->where('id', $longerAddressRowId)->exists()
        ? ok('a row quoting a longer address with the person\'s inside it is not theirs, and stays')
        : bad('the deletion erased a row quoting someone else\'s longer address');
    Capsule::table('tblactivitylog')->where('id', $longerLoginRowId)->exists()
        ? ok('a row naming a login whose id ends in the person\'s is not theirs, and stays')
        : bad('the deletion erased a row naming someone else\'s login');
    $written = Capsule::table('tblactivitylog')->where('id', '>', $lastLogIdBefore)
        ->get(['id', 'userid', 'user_id', 'description'])->all();
    $written === []
        ? ok('no activity row written during the deletion outlives it')
        : bad('activity rows written during the deletion outlive it: ' . json_encode($written));
    if ($legacyTable) {
        (string) Capsule::table('mod_vpnhood_iap_legacy_subs')->where('purchase_key', "$marker-legacy")->value('email') === ''
            ? ok('the old store\'s hand-over row the account claimed keeps no address')
            : bad('the claimed hand-over row keeps the address');
    } else {
        ok('no old-store hand-over table on this install');
    }

    userRow($userIds['main']) === null
        ? ok('the account row is gone')
        : bad('the account row survived the deletion');

    Capsule::table('mod_vpnhood_iap_identities')->where('user_id', $userIds['main'])->count() === 0
        ? ok('every sign-in identity is gone')
        : bad('a sign-in identity survived');

    $liveSessions = 0;
    foreach ([$tokenA, $tokenB] as $token) {
        try {
            $sessions->resolve($token);
            $liveSessions++;
        } catch (ApiException $e) {
            // expected: every device is signed out
        }
    }
    $liveSessions === 0
        ? ok('every session on every device is dead')
        : bad("$liveSessions session(s) still resolve after deletion");

    $purchase = (array) Capsule::table('mod_vpnhood_iap_purchases')->where('id', $purchaseId)->first();
    // the dead pointer is deliberate: the journal keeps the same numeric id, and it is
    // what lets Restore Purchases re-attach the purchase to the person's next account
    ((int) $purchase['user_id'] === $userIds['main'] && $purchase['status'] === 'provisioned')
        ? ok('the purchase survives with a dead owner pointer — the paid gate stays open, restore can re-attach')
        : bad('the purchase was altered: ' . json_encode($purchase));

    $client = (array) Capsule::table('tblclients')->where('id', $clientIds['main'])->first();
    ($client['email'] === "deleted-{$clientIds['main']}@anonymized.invalid"
        && $client['firstname'] === 'Deleted' && $client['lastname'] === 'Account')
        ? ok('the customer record is anonymized with placeholders')
        : bad('anonymization is wrong: ' . json_encode(['email' => $client['email'], 'first' => $client['firstname']]));

    $client['status'] === 'Closed'
        ? ok('the customer record is closed')
        : bad("the customer record is {$client['status']}, expected Closed");

    Capsule::table('mod_vpnhood_iap_deletions')->where('client_id', $clientIds['main'])->count() === 1
        ? ok('the deletion is journalled (numeric ids only)')
        : bad('no journal row was written');

    // == 2. a re-run finishes what a failed run left =========================
    // a close that failed after the record was anonymized leaves it open
    $reopened = localAPI('UpdateClient', ['clientid' => $clientIds['main'], 'status' => 'Active', 'skipvalidation' => true]);
    (new AccountDeletionService())->deleteClient($clientIds['main']);
    $status = (string) Capsule::table('tblclients')->where('id', $clientIds['main'])->value('status');
    (($reopened['result'] ?? '') === 'success' && $status === 'Closed')
        ? ok('a re-run closes a record a failed run left open')
        : bad("after a re-run the record is $status, expected Closed: " . json_encode($reopened));

    // == 3. an active web service NO LONGER blocks — billing stops instead ====
    // (lifecycle §8: cancel at the END of the paid period, keep the key running,
    // journal the agreement reference.)
    $productId = (int) (one($db, "SELECT id FROM tblproducts WHERE paytype = 'recurring' ORDER BY id LIMIT 1")['id'] ?? 0);
    $webServiceId = 0;
    if ($productId === 0) {
        bad('no recurring product on this install — cannot exercise the cancel-at-period-end path');
    } else {
        $clientIds['web'] = makeClient("$marker-web@vpnhood.test");
        $order = localAPI('AddOrder', [
            'clientid'      => $clientIds['web'],
            'pid'           => [$productId],
            'billingcycle'  => ['monthly'],
            'paymentmethod' => 'banktransfer',
            'noemail'       => true,
        ]);
        if (($order['result'] ?? '') !== 'success') {
            bad('AddOrder failed: ' . json_encode($order));
        } else {
            $orderIds['web'] = (int) $order['orderid'];
            $webServiceId = (int) (one($db, 'SELECT id FROM tblhosting WHERE orderid = ? LIMIT 1', [$orderIds['web']])['id'] ?? 0);
            localAPI('UpdateClientProduct', ['serviceid' => $webServiceId, 'status' => 'Active']);
            // the order's invoice as if made before "Store Client Data Snapshot" was on
            $webInvoiceIds = Capsule::table('tblinvoices')->where('userid', $clientIds['web'])->pluck('id')->all();
            Capsule::table('mod_invoicedata')->whereIn('invoiceid', $webInvoiceIds ?: [0])->delete();
            \WHMCS\Config\Setting::setValue('StoreClientDataSnapshotOnInvoiceCreation', '');
            $webInvoiceIds !== []
                ? ok('the web order has invoice #' . implode(', #', $webInvoiceIds) . ', with no snapshot, the setting off')
                : bad('the web order made no invoice: the snapshot path goes untested');

            // the client's app account dies with it; one that only carries the client's address
            // on its row is not shown to be the person's (anyone may sign up with any address)
            $userIds['webBound'] = makeUser("$marker-webbound", $clientIds['web']);
            $userIds['webNamesake'] = makeUser("$marker-web", null);

            (new AccountDeletionService())->deleteClient($clientIds['web']);
            ok('deletion proceeded despite an active web service — nothing blocks it');

            (userRow($userIds['webBound']) === null && Capsule::table('mod_vpnhood_iap_deletions')
                ->where('client_id', $clientIds['web'])->where('user_id', $userIds['webBound'])->exists())
                ? ok('the app account bound to the client is gone with it, and journalled')
                : bad('the app account bound to the client survived the web deletion, or went unjournalled');
            userRow($userIds['webNamesake']) !== null
                ? ok('an app account that only carries the client\'s address on its row stays')
                : bad('the web deletion erased an app account it found by the address on its row');

            $cancelRow = one($db, 'SELECT id, type FROM tblcancelrequests WHERE relid = ? ORDER BY id DESC', [$webServiceId]);
            ($cancelRow !== null && stripos((string) $cancelRow['type'], 'End of Billing') !== false)
                ? ok('billing is cancelled at the END of the paid period (cancellation request recorded)')
                : bad('no end-of-period cancellation request: ' . json_encode($cancelRow));

            (one($db, 'SELECT domainstatus FROM tblhosting WHERE id = ?', [$webServiceId])['domainstatus'] ?? '?') === 'Active'
                ? ok('the service keeps running — the key still runs out the time already bought')
                : bad('the service was terminated instead of cancelled at period end');

            $webClient = (array) Capsule::table('tblclients')->where('id', $clientIds['web'])->first();
            // Inactive, not Closed: closing terminates products, and the person's
            // paid-for key must keep running to the end of what was bought
            ($webClient['email'] === "deleted-{$clientIds['web']}@anonymized.invalid" && $webClient['status'] === 'Inactive')
                ? ok('the customer behind the running service is erased (Inactive — closing would kill the key)')
                : bad('web customer not erased: ' . json_encode(['email' => $webClient['email'], 'status' => $webClient['status']]));

            $journal = one($db, 'SELECT details FROM mod_vpnhood_iap_deletions WHERE client_id = ? ORDER BY id DESC', [$clientIds['web']]);
            $details = json_decode((string) ($journal['details'] ?? ''), true);
            (is_array($details) && !empty($details['cancelledAtPeriodEnd'])
                && (int) ($details['cancelledAtPeriodEnd'][0]['service'] ?? 0) === $webServiceId)
                ? ok('the journal keeps the cancelled service + agreement reference (details)')
                : bad('journal details missing the agreement reference: ' . json_encode($journal));

            // == 3b. the invoices keep the buyer as issued, in WHMCS's own snapshot =====
            // The order's invoice was made as if before "Store Client Data Snapshot" was
            // on: no snapshot, and the setting off. The deletion turned it on and took one
            // before the client row became placeholders.
            \WHMCS\Config\Setting::getValue('StoreClientDataSnapshotOnInvoiceCreation') === 'on'
                ? ok('the deletion keeps "Store Client Data Snapshot" on')
                : bad('the deletion left "Store Client Data Snapshot" off');
            $erasedDetails = ['email', 'phonenumber', 'phonenumberformatted', 'telephoneNumber', 'notes', 'lastlogin',
                'cctype', 'cclastfour', 'gatewayid', 'billingcid'];
            // (dev keeps no tax id: WHMCS drops the fixture's, so the snapshot's goes unchecked)
            $issuedTo = ['firstname' => 'Delete', 'lastname' => 'Test', 'companyname' => 'Delete Test Co', 'address2' => 'Suite 7'];
            foreach ($webInvoiceIds as $invoiceId) {
                $shown = (array) ((new \WHMCS\Invoice((int) $invoiceId))->getOutput()['clientsdetails'] ?? []);
                $buyer = array_intersect_key($shown, $issuedTo);
                $buyer == $issuedTo
                    ? ok("invoice #$invoiceId still shows the buyer as issued: name, company and address")
                    : bad("invoice #$invoiceId lost the buyer: " . json_encode($buyer));
                $snapshot = json_decode((string) Capsule::table('mod_invoicedata')->where('invoiceid', $invoiceId)
                    ->value('clientsdetails'), true) ?: [];
                $kept = array_filter(array_intersect_key($snapshot, array_flip($erasedDetails)),
                    fn ($value) => $value !== '' && $value !== []);
                ($snapshot !== [] && $kept === [])
                    ? ok("invoice #$invoiceId's snapshot keeps no email, phone, notes, last login or card details")
                    : bad("invoice #$invoiceId's snapshot keeps: " . json_encode(array_keys($kept)));
            }
        }
    }

    // == 4. the real endpoint, end to end =====================================
    $clientIds['http'] = makeClient("$marker-http@vpnhood.test");
    $userIds['http'] = makeUser("$marker-http", $clientIds['http']);
    $httpToken = (new SessionService())->issue($userIds['http'])['token'];

    // NO preview endpoint, deliberately (lifecycle §5/§10): the confirmation shows
    // no codes and no counts — the warning is the whole story
    $curl = curl_init(API_URL . '/account/deletion-preview');
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $httpToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $previewBody = (string) curl_exec($curl);
    $previewStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $previewStatus === 404
        ? ok('GET /account/deletion-preview no longer exists (404) — the screen warns, the mail delivers')
        : bad("deletion-preview should be gone, answered $previewStatus: " . substr($previewBody, 0, 200));

    $curl = curl_init(API_URL . '/account');
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $httpToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $status === 204
        ? ok('DELETE /account answers 204 for a signed-in account')
        : bad("DELETE /account answered $status: " . substr($body, 0, 200));

    userRow($userIds['http']) === null
        ? ok('the endpoint erased the account for real')
        : bad('the endpoint answered but the account survived');

    try {
        (new SessionService())->resolve($httpToken);
        bad('the session used to delete still resolves');
    } catch (ApiException $e) {
        ok('the session that performed the deletion is dead');
    }

    // == 5. deletion on request, from the admin's Accounts tab ================
    // an app-only account, found by the address it signs in with; another whose row
    // carries that address while its sign-in method reports a different one is not it
    $askedAddress = "$marker-asked@vpnhood.test";
    $userIds['asked'] = makeUser("$marker-asked", null);
    $userIds['askedNamesake'] = makeUser("$marker-namesake", null);
    Capsule::table('mod_vpnhood_iap_users')->where('id', $userIds['askedNamesake'])->update(['email' => $askedAddress]);
    foreach (['asked' => $askedAddress, 'askedNamesake' => "$marker-moved@vpnhood.test"] as $key => $address) {
        Capsule::table('mod_vpnhood_iap_identities')->insert(['user_id' => $userIds[$key], 'provider' => 'google',
            'provider_subject' => "$marker-$key", 'email' => $address,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }
    require_once WEBROOT . '/modules/addons/vpnhoodiap/vpnhoodiap.php';
    if (!isset($_SESSION)) {
        $_SESSION = []; // no session in the CLI; the tab's CSRF token lives in it
    }
    $adminPage = function (string $method, array $request, array $post = []): string {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_REQUEST = $request + $post;
        $_POST = $post + ['token' => vpnhoodiap_csrfToken()];
        ob_start();
        vpnhoodiap_output(['modulelink' => 'addonmodules.php?module=vpnhoodiap']);
        return (string) ob_get_clean();
    };
    $found = $adminPage('GET', ['tab' => 'accounts', 'email' => $askedAddress]);
    (str_contains($found, '<td>#' . $userIds['asked'] . '</td>') && !str_contains($found, '<td>#' . $userIds['askedNamesake'] . '</td>'))
        ? ok('the Accounts tab finds the account by the address it signs in with, not by the one on its row')
        : bad('the Accounts tab listed the wrong accounts for the address');
    $refused = $adminPage('POST', ['tab' => 'accounts'], ['do' => 'account_delete', 'id' => $userIds['asked']]);
    (str_contains($refused, 'Tick the confirmation box') && userRow($userIds['asked']) !== null)
        ? ok('without the confirmation box ticked, nothing is deleted')
        : bad('the Accounts tab deleted without its confirmation');
    $deleted = $adminPage('POST', ['tab' => 'accounts'], ['do' => 'account_delete', 'id' => $userIds['asked'], 'confirm' => 'yes']);
    (str_contains($deleted, 'is deleted') && userRow($userIds['asked']) === null
        && Capsule::table('mod_vpnhood_iap_deletions')->where('user_id', $userIds['asked'])->exists()
        && userRow($userIds['askedNamesake']) !== null)
        ? ok('the Accounts tab deletes the account it found, journalled, and leaves the other')
        : bad('the Accounts tab did not delete as asked: ' . strip_tags(substr($deleted, 0, 300)));
} finally {
    // == cleanup — test fixtures never linger ================================
    foreach ($userIds as $userId) {
        Capsule::table('mod_vpnhood_iap_sessions')->where('user_id', $userId)->delete();
        Capsule::table('mod_vpnhood_iap_identities')->where('user_id', $userId)->delete();
        Capsule::table('mod_vpnhood_iap_users')->where('id', $userId)->delete();
    }
    Capsule::table('mod_vpnhood_iap_purchases')->where('purchase_key', "$marker-purchase")->delete();
    foreach ($clientIds as $clientId) {
        Capsule::table('mod_vpnhood_iap_deletions')->where('client_id', $clientId)->delete();
    }
    // DeleteClient takes the invoices but leaves WHMCS's snapshots of them
    $fixtureInvoiceIds = Capsule::table('tblinvoices')->whereIn('userid', $clientIds ?: [0])->pluck('id')->all();
    foreach ($orderIds as $orderId) {
        localAPI('DeleteOrder', ['orderid' => $orderId]);
    }
    foreach ($clientIds as $clientId) {
        localAPI('DeleteClient', ['clientid' => $clientId, 'deleteusers' => true]);
    }
    Capsule::table('mod_invoicedata')->whereIn('invoiceid', $fixtureInvoiceIds ?: [0])->delete();
    // the snapshot test turns the setting off; the install keeps it on
    AccountDeletionService::keepInvoiceSnapshotsOn();
    if (!empty($webServiceId)) {
        // no localAPI command removes cancellation requests — cleanup-only raw delete
        $db->prepare('DELETE FROM tblcancelrequests WHERE relid = ?')->execute([$webServiceId]);
    }
    // what DeleteClient leaves: the login without a link, and the raw fixtures
    if ($loginIds !== []) {
        Capsule::table('tblusers_clients')->whereIn('auth_user_id', $loginIds)->delete();
        Capsule::table('tblusers')->whereIn('id', $loginIds)->delete();
    }
    if ($customFieldId > 0) {
        Capsule::table('tblcustomfieldsvalues')->where('fieldid', $customFieldId)->delete();
        Capsule::table('tblcustomfields')->where('id', $customFieldId)->delete();
    }
    if ($legacyTable) {
        Capsule::table('mod_vpnhood_iap_legacy_subs')->where('purchase_key', "$marker-legacy")->delete();
    }
    foreach (Capsule::table('tbltickets')->where('title', 'like', "%$marker%")->pluck('id') as $ticketId) {
        localAPI('DeleteTicket', ['ticketid' => (int) $ticketId]);
    }
    Capsule::table('tblauthn_account_links')->where('remote_user_id', 'like', "$marker%")->delete();
    Capsule::table('tblactivitylog')->where('description', 'like', "%$marker%")->delete();
    ok('fixtures removed');
}

finish();
