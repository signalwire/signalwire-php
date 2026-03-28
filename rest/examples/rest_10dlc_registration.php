<?php
/**
 * Example: 10DLC brand and campaign compliance registration.
 *
 * WARNING: This example interacts with the real 10DLC registration system.
 * Brand and campaign registrations may have side effects and costs.
 *
 * Set these env vars:
 *   SIGNALWIRE_PROJECT_ID   - your SignalWire project ID
 *   SIGNALWIRE_API_TOKEN    - your SignalWire API token
 *   SIGNALWIRE_SPACE        - your SignalWire space
 */

require 'vendor/autoload.php';

use SignalWire\REST\RestClient;

$client = new RestClient(
    project: $_ENV['SIGNALWIRE_PROJECT_ID'] ?? die("Set SIGNALWIRE_PROJECT_ID\n"),
    token:   $_ENV['SIGNALWIRE_API_TOKEN']  ?? die("Set SIGNALWIRE_API_TOKEN\n"),
    host:    $_ENV['SIGNALWIRE_SPACE']      ?? die("Set SIGNALWIRE_SPACE\n"),
);

function safe(string $label, callable $fn): mixed
{
    try {
        $result = $fn();
        echo "  {$label}: OK\n";
        return $result;
    } catch (\Exception $e) {
        echo "  {$label}: failed ({$e->getMessage()})\n";
        return null;
    }
}

// 1. Register a brand
echo "Registering 10DLC brand...\n";
$brand = safe('Brand', fn() => $client->registry->brands->create(
    companyName: 'Acme Corp',
    ein:         '12-3456789',
    entityType:  'PRIVATE_PROFIT',
    vertical:    'TECHNOLOGY',
    website:     'https://acme.example.com',
    country:     'US',
));
$brandId = $brand ? $brand['id'] : null;

// 2. List brands
echo "\nListing brands...\n";
$brands = safe('List brands', fn() => $client->registry->brands->list());
if ($brands) {
    foreach (($brands['data'] ?? []) as $b) {
        echo "  - {$b['id']}: " . ($b['name'] ?? 'unnamed') . "\n";
    }
    if (!$brandId && !empty($brands['data'])) {
        $brandId = $brands['data'][0]['id'];
    }
}

// 3. Get brand details
if ($brandId) {
    $detail = safe('Brand detail', fn() => $client->registry->brands->get($brandId));
    if ($detail) {
        echo "\nBrand detail: " . ($detail['name'] ?? 'N/A')
            . " (" . ($detail['state'] ?? 'N/A') . ")\n";
    }
}

// 4. Create a campaign under the brand
$campaignId = null;
if ($brandId) {
    echo "\nCreating campaign...\n";
    $campaign = safe('Campaign', fn() => $client->registry->brands->createCampaign(
        $brandId,
        useCase:       'MIXED',
        description:   'Customer notifications and support messages',
        sampleMessage: 'Your order #12345 has shipped.',
    ));
    $campaignId = $campaign ? $campaign['id'] : null;
}

// 5. List campaigns for the brand
if ($brandId) {
    echo "\nListing brand campaigns...\n";
    $campaigns = safe('List campaigns', fn() =>
        $client->registry->brands->listCampaigns($brandId)
    );
    if ($campaigns) {
        foreach (($campaigns['data'] ?? []) as $c) {
            echo "  - {$c['id']}: " . ($c['name'] ?? 'unknown') . "\n";
            $campaignId ??= $c['id'];
        }
    }
}

// 6. Get and update campaign
if ($campaignId) {
    $campDetail = safe('Get campaign', fn() => $client->registry->campaigns->get($campaignId));
    if ($campDetail) {
        echo "\nCampaign: " . ($campDetail['name'] ?? 'N/A')
            . " (" . ($campDetail['state'] ?? 'N/A') . ")\n";
    }
    safe('Update campaign', fn() =>
        $client->registry->campaigns->update($campaignId, description: 'Updated: customer notifications')
    );
}

// 7. Create an order to assign numbers
$orderId = null;
if ($campaignId) {
    echo "\nCreating number assignment order...\n";
    $order = safe('Order', fn() =>
        $client->registry->campaigns->createOrder($campaignId, phoneNumbers: ['+15125551234'])
    );
    $orderId = $order ? $order['id'] : null;
}

// 8. Get order status
if ($orderId) {
    $orderDetail = safe('Order status', fn() => $client->registry->orders->get($orderId));
    if ($orderDetail) {
        echo "  Order status: " . ($orderDetail['status'] ?? 'N/A') . "\n";
    }
}

// 9. List campaign numbers and orders
if ($campaignId) {
    echo "\nListing campaign numbers...\n";
    $numbers = safe('List numbers', fn() => $client->registry->campaigns->listNumbers($campaignId));
    if ($numbers) {
        foreach (($numbers['data'] ?? []) as $n) {
            echo "  - " . ($n['phone_number'] ?? $n['id'] ?? 'unknown') . "\n";
        }
    }

    $orders = safe('List orders', fn() => $client->registry->campaigns->listOrders($campaignId));
    if ($orders) {
        foreach (($orders['data'] ?? []) as $o) {
            echo "  - Order {$o['id']}: " . ($o['status'] ?? 'unknown') . "\n";
        }
    }
}

// 10. Unassign numbers (clean up)
if ($campaignId) {
    echo "\nUnassigning numbers...\n";
    $nums = safe('Get numbers', fn() => $client->registry->campaigns->listNumbers($campaignId));
    if ($nums) {
        foreach (($nums['data'] ?? []) as $n) {
            safe("Unassign {$n['id']}", fn() => $client->registry->numbers->delete($n['id']));
        }
    }
}
