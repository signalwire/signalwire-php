<?php

declare(strict_types=1);
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

/** Read a required setting from the environment, or stop with a clear message. */
function env(string $name): string
{
    $value = $_ENV[$name] ?? null;
    if (!is_string($value) || $value === '') {
        exit("Set {$name}\n");
    }
    return $value;
}

$client = new RestClient(
    project: env('SIGNALWIRE_PROJECT_ID'),
    token:   env('SIGNALWIRE_API_TOKEN'),
    host:    env('SIGNALWIRE_SPACE'),
);

/**
 * Run an SDK call, reporting OK/failed instead of aborting the demo.
 *
 * Every REST method returns the decoded JSON body as array<string,mixed>, so
 * that is what a success yields; a failure yields null.
 *
 * @param callable(): mixed $fn
 * @return array<array-key,mixed>|null
 */
function safe(string $label, callable $fn): ?array
{
    try {
        $result = $fn();
        $result = is_array($result) ? $result : null;
        echo "  {$label}: OK\n";
        return $result;
    } catch (\Exception $e) {
        echo "  {$label}: failed ({$e->getMessage()})\n";
        return null;
    }
}

/**
 * Read a string field out of a decoded response row.
 *
 * REST bodies are array<string,mixed> — the server decides the shape — so a
 * field is `mixed` until checked. Numbers are stringified (an id may arrive as
 * either); anything else yields $default.
 */
function field(mixed $row, string $key, string $default = ''): string
{
    if (!is_array($row)) {
        return $default;
    }
    $value = $row[$key] ?? null;
    if (is_string($value)) {
        return $value;
    }

    return is_int($value) || is_float($value) ? (string) $value : $default;
}

/**
 * Narrow a `mixed` to a list that is safe to foreach/count/array_slice.
 * A missing or non-array value yields an empty list.
 *
 * @return list<mixed>
 */
function rows(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * The `data` collection of a list response, narrowed to a list.
 *
 * @return list<mixed>
 */
function dataRows(mixed $response): array
{
    return is_array($response) ? rows($response['data'] ?? []) : [];
}

// 1. Register a brand
echo "Registering 10DLC brand...\n";
$brand = safe('Brand', fn () => $client->registry()->brands()->create([
    'name'                => 'Acme Corp',
    'company_name'        => 'Acme Corp',
    'ein'                 => '12-3456789',
    'legal_entity_type'   => 'PRIVATE_PROFIT',
    'company_vertical'    => 'TECHNOLOGY',
    'company_website'     => 'https://acme.example.com',
    'ein_issuing_country' => 'US',
]));
$brandId = $brand ? ($brand['id'] ?? null) : null;

// 2. List brands
echo "\nListing brands...\n";
$brands = safe('List brands', fn () => $client->registry()->brands()->list());
if ($brands) {
    foreach (dataRows($brands) as $b) {
        echo '  - ' . field($b, 'id') . ': ' . field($b, 'name', 'unnamed') . "\n";
    }
    if (!$brandId && !empty($brands['data'])) {
        $brandId = $brands['data'][0]['id'];
    }
}

// 3. Get brand details
if ($brandId) {
    $detail = safe('Brand detail', fn () => $client->registry()->brands()->get($brandId));
    if ($detail) {
        echo "\nBrand detail: " . field($detail, 'name', 'N/A')
            . ' (' . field($detail, 'state', 'N/A') . ")\n";
    }
}

// 4. Create a campaign under the brand
$campaignId = null;
if ($brandId) {
    echo "\nCreating campaign...\n";
    $campaign = safe('Campaign', fn () => $client->registry()->brands()->createCampaign(
        $brandId,
        [
            'name'         => 'Order Notifications',
            'sms_use_case' => 'MIXED',
            'description'  => 'Customer notifications and support messages',
            'sample1'      => 'Your order #12345 has shipped.',
        ],
    ));
    $campaignId = $campaign ? ($campaign['id'] ?? null) : null;
}

// 5. List campaigns for the brand
if ($brandId) {
    echo "\nListing brand campaigns...\n";
    $campaigns = safe(
        'List campaigns',
        fn () =>
        $client->registry()->brands()->listCampaigns($brandId)
    );
    if ($campaigns) {
        foreach (dataRows($campaigns) as $c) {
            echo '  - ' . field($c, 'id') . ': ' . field($c, 'name', 'unknown') . "\n";
            $campaignId ??= $c['id'];
        }
    }
}

// 6. Get and update campaign
if ($campaignId) {
    $campDetail = safe('Get campaign', fn () => $client->registry()->campaigns()->get($campaignId));
    if ($campDetail) {
        echo "\nCampaign: " . field($campDetail, 'name', 'N/A')
            . ' (' . field($campDetail, 'state', 'N/A') . ")\n";
    }
    safe(
        'Update campaign',
        fn () =>
        $client->registry()->campaigns()->update($campaignId, name: 'Updated Campaign')
    );
}

// 7. Create an order to assign numbers
$orderId = null;
if ($campaignId) {
    echo "\nCreating number assignment order...\n";
    $order = safe(
        'Order',
        fn () =>
        $client->registry()->campaigns()->createOrder($campaignId, phoneNumbers: ['+15125551234'])
    );
    $orderId = $order ? ($order['id'] ?? null) : null;
}

// 8. Get order status
if ($orderId) {
    $orderDetail = safe('Order status', fn () => $client->registry()->orders()->get($orderId));
    if ($orderDetail) {
        echo '  Order status: ' . field($orderDetail, 'status', 'N/A') . "\n";
    }
}

// 9. List campaign numbers and orders
if ($campaignId) {
    echo "\nListing campaign numbers...\n";
    $numbers = safe('List numbers', fn () => $client->registry()->campaigns()->listNumbers($campaignId));
    if ($numbers) {
        foreach (dataRows($numbers) as $n) {
            echo '  - ' . ($n['phone_number'] ?? $n['id'] ?? 'unknown') . "\n";
        }
    }

    $orders = safe('List orders', fn () => $client->registry()->campaigns()->listOrders($campaignId));
    if ($orders) {
        foreach (dataRows($orders) as $o) {
            echo '  - Order ' . field($o, 'id') . ': ' . field($o, 'status', 'unknown') . "\n";
        }
    }
}

// 10. Unassign numbers (clean up)
if ($campaignId) {
    echo "\nUnassigning numbers...\n";
    $nums = safe('Get numbers', fn () => $client->registry()->campaigns()->listNumbers($campaignId));
    if ($nums) {
        foreach (dataRows($nums) as $n) {
            safe('Unassign ' . field($n, 'id'), fn () => $client->registry()->numbers()->delete($n['id']));
        }
    }
}
