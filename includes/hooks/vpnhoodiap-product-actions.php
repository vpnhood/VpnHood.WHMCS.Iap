<?php

/**
 * VpnHood! IAP — remove the money-moving actions from store-bought services.
 *
 * The provisioning module badges its own overview tab with "purchased via Google Play"
 * and the policy text, but the actions around it are WHMCS's, so no template can touch
 * them. Left alone the page offers "Request Cancellation" — which would cancel the
 * WHMCS service while the store subscription kept renewing and charging, the worst of
 * both worlds: the customer loses the VPN and keeps paying Google. Renew and
 * Upgrade/Downgrade are removed for the same reason: both raise an invoice HERE, which
 * flatly contradicts the notice on the same page saying we never charge for this order.
 *
 * A store subscription is changed only where it was bought.
 *
 * Two mechanisms, because themes differ and this addon ships to installs it has never
 * seen: six/nexus read template flags ($showcancelbutton, $packagesupgrade,
 * $showRenewServiceButton — verified in the shipped templates), while lagom2 ignores
 * those and builds the items as children of the 'Service Details Actions' sidebar. So
 * both are handled, and the sidebar pass matches on the action in each child's URI
 * rather than on a child NAME, which is theme- and language-dependent.
 *
 * Keyed on the 'purchasedVia' service property, so it holds for both provisioning
 * flavours (vpnhoodstore, vpnhoodpartner) without a lookup into this addon's tables.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/** True when this service was sold through an app store. */
function vpnhoodiap_serviceIsStoreBought(int $serviceId): bool
{
    static $cache = [];
    if ($serviceId <= 0) {
        return false;
    }
    if (array_key_exists($serviceId, $cache)) {
        return $cache[$serviceId];
    }
    try {
        $service = \WHMCS\Service\Service::find($serviceId);
        $cache[$serviceId] = $service !== null
            && (string) $service->serviceProperties->get('purchasedVia') !== '';
    } catch (\Throwable $e) {
        // unreadable state must never strip actions from an ordinary service
        logModuleCall('vpnhoodiap', 'hook.productActions', (string) $serviceId, $e->getMessage(), '');
        $cache[$serviceId] = false;
    }
    return $cache[$serviceId];
}

/** The service whose details page is being rendered, or 0. */
function vpnhoodiap_currentServiceId(): int
{
    return (int) ($_REQUEST['id'] ?? 0);
}

// themes that gate the buttons on template flags (six, nexus)
add_hook('ClientAreaPageProductDetails', 1, function (array $vars) {
    $serviceId = (int) ($vars['id'] ?? vpnhoodiap_currentServiceId());
    if (!vpnhoodiap_serviceIsStoreBought($serviceId)) {
        return [];
    }

    return [
        'showcancelbutton'       => false,
        'packagesupgrade'        => false,
        'showRenewServiceButton' => false,
    ];
});

/**
 * Sidebar items this addon takes off a store-bought service, by child NAME.
 *
 * 'RefundRequest' is the hub's own "Request a Refund" item (VpnHood.WHMCS
 * includes/hooks/vpnhoodcustomhook.php), which opens a support ticket asking us for
 * money back we never took — the single most misleading thing that could sit next to
 * a "billed by Google Play" notice. It is matched by name because its URI is an
 * ordinary submitticket.php link that no action pattern would catch. Removing a name
 * that is not present is a no-op, so installs without that hook are unaffected.
 */
const VPNHOODIAP_REMOVED_SIDEBAR_ITEMS = ['RefundRequest', 'Cancellation Request'];

// themes that build the same actions as sidebar children (lagom2), plus the hub's own
// added items. Priority 200 so it runs after BOTH lagom2's sidebar hook (priority 1)
// and vpnhoodcustomhook.php's (priority 100) — an item can only be removed after the
// hook that adds it has run, and matching that priority would leave the order to
// registration sequence.
add_hook('ClientAreaPrimarySidebar', 200, function ($primarySidebar) {
    try {
        $serviceId = vpnhoodiap_currentServiceId();
        if ($serviceId <= 0 || !vpnhoodiap_serviceIsStoreBought($serviceId)) {
            return;
        }

        $actions = $primarySidebar->getChild('Service Details Actions');
        if ($actions === null) {
            return;
        }

        foreach ($actions->getChildren() as $name => $child) {
            $uri = (string) $child->getUri();
            $isCancel = str_contains($uri, 'action=cancel');
            $isUpgrade = str_contains($uri, 'upgrade.php');
            $isRenew = str_contains($uri, 'action=productdetails') && str_contains($uri, 'renew');
            if (in_array($name, VPNHOODIAP_REMOVED_SIDEBAR_ITEMS, true) || $isCancel || $isUpgrade || $isRenew) {
                $actions->removeChild($name);
            }
        }
    } catch (\Throwable $e) {
        // a sidebar that cannot be filtered must not take the page down with it
        logModuleCall('vpnhoodiap', 'hook.productSidebar', '', $e->getMessage(), '');
    }
});
