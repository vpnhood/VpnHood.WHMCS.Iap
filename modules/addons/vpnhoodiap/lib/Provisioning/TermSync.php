<?php

namespace WHMCS\Module\Addon\VpnHoodIap\Provisioning;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodIap\IapRepository;
use WHMCS\Module\Addon\VpnHoodIap\Stores\Dto\PurchaseRecord;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Keeps the WHMCS term — and through it the delivered code — in step with the STORE's paid time.
 *
 * The store is the clock: it bills at the hour of the original purchase, and its paid time ends at
 * that hour. WHMCS keeps dates, and the provisioning module writes the service's nextduedate to the
 * code as that date's midnight — so a term that merely matched the store's date would end the code
 * hours before the store renews. The term is therefore the day AFTER the store's expiry (UTC): the
 * code outlives the paid time, the renewal invoice is generated and paid before it, and WHMCS
 * advances from a date that stays a day ahead.
 *
 * Forward only: a term already past the store's is left alone (time paid for is never taken back;
 * the next renewal catches up). One route to the code, WHMCS-native: UpdateClientProduct moves the
 * date and ModuleCustom runs the product module's own Renew, which re-syncs the code from it — the
 * addon never touches a provisioning API (CLAUDE.md). A sync that fails is loud: the ledger already
 * carries the store's expiry, so the account would read premium while the code dies (2026-10-01).
 */
class TermSync
{
    public function __construct(private readonly IapRepository $repo)
    {
    }

    /** The WHMCS due date a store expiry calls for: the day after it, UTC. */
    public static function dueDateFor(PurchaseRecord $record): ?string
    {
        return $record->expiryTimeUnix === null ? null : gmdate('Y-m-d', $record->expiryTimeUnix + 86400);
    }

    /**
     * Move the service's term (and code) up to the store's paid time when it is behind.
     *
     * @return string synced | current | skipped-<reason> | failed
     */
    public function sync(int $serviceId, PurchaseRecord $record): string
    {
        $dueDate = self::dueDateFor($record);
        if ($dueDate === null) {
            return 'skipped-no-expiry';
        }
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first(['domainstatus', 'nextduedate']);
        if ($service === null || !in_array((string) $service->domainstatus, ['Active', 'Suspended'], true)) {
            return 'skipped-service-ended';
        }
        if ((string) $service->nextduedate >= $dueDate) {
            return 'current';
        }
        $moved = localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'nextduedate' => $dueDate]);
        if (($moved['result'] ?? '') !== 'success') {
            $this->repo->alert("vpnhoodiap: service #$serviceId could not be moved to $dueDate (the store's paid time): "
                . json_encode($moved) . ' — its code will end before the paid time.');
            return 'failed';
        }
        return $this->renewCode($serviceId) ? 'synced' : 'failed';
    }

    /** Re-sync the code from the service's current nextduedate: the product module's own Renew. */
    public function renewCode(int $serviceId): bool
    {
        $result = localAPI('ModuleCustom', ['serviceid' => $serviceId, 'func_name' => 'Renew']);
        if (($result['result'] ?? '') === 'success') {
            return true;
        }
        $this->repo->alert("vpnhoodiap: the provisioning module's Renew failed for service #$serviceId: "
            . (string) ($result['message'] ?? json_encode($result)) . ' — its code will end before the paid time.');
        return false;
    }

    /**
     * Hub installs only: when the delivered code's own clock is behind the term (a Renew that failed
     * earlier), run Renew again. A partner install cannot read the clock and answers 'unknown'.
     *
     * @return string repaired | current | unknown | failed
     */
    public function repairCode(int $serviceId): string
    {
        $state = (new DeliveryReader())->readCodeState($serviceId);
        if ($state['expiresAt'] === null) {
            return 'unknown';
        }
        $nextDue = (string) Capsule::table('tblhosting')->where('id', $serviceId)->value('nextduedate');
        if ($nextDue === '' || strtotime($state['expiresAt']) >= strtotime($nextDue . 'T00:00:00Z')) {
            return 'current';
        }
        return $this->renewCode($serviceId) ? 'repaired' : 'failed';
    }
}
