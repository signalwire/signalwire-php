<?php
/**
 * Custom Routing Callbacks with SWMLService
 *
 * Demonstrates dynamic request routing using routing callbacks:
 * - Multiple endpoint paths (/main, /customer, /product)
 * - Callback functions to process requests at specific paths
 * - Different SWML content based on request path
 */

require 'vendor/autoload.php';

use SignalWire\SWML\SWMLService;

$service = new SWMLService(
    name:  'routing-example',
    route: '/main',
    host:  '0.0.0.0',
    port:  3000,
);

// Default document for /main
$service->addAnswerVerb();
$service->addVerb('play', ['url' => 'say:Welcome to our main line.']);
$service->addVerb('prompt', [
    'play'       => 'say:Press 1 for customer service, or 2 for product information.',
    'max_digits' => 1,
    'terminators' => '#',
]);
$service->addHangupVerb();

// Register routing callback for /customer
$service->registerRoutingCallback('/customer', function (?array $requestData) use ($service) {
    $service->resetDocument();
    $service->addAnswerVerb();

    $name = $requestData['customer_name'] ?? null;
    if ($name) {
        $service->addVerb('play', ['url' => "say:Hello {$name}, welcome to customer service."]);
    } else {
        $service->addVerb('play', ['url' => 'say:Welcome to customer service.']);
    }

    $service->addVerb('play', ['url' => 'say:An agent will be with you shortly.']);
    $service->addVerb('connect', [
        'to'      => '+15551234567',
        'timeout' => 30,
    ]);
    $service->addHangupVerb();
    return null;
});

// Register routing callback for /product
$service->registerRoutingCallback('/product', function (?array $requestData) use ($service) {
    $service->resetDocument();
    $service->addAnswerVerb();

    $product = $requestData['product_id'] ?? null;
    if ($product) {
        $service->addVerb('play', ['url' => "say:Thank you for your interest in product {$product}."]);
    } else {
        $service->addVerb('play', ['url' => 'say:Welcome to our product information line.']);
    }

    $service->addVerb('prompt', [
        'play'       => 'say:Press 1 for pricing, 2 for features, or 3 to speak with sales.',
        'max_digits' => 1,
        'terminators' => '#',
    ]);
    $service->addHangupVerb();
    return null;
});

echo "Starting Routing Example Service\n";
echo "Endpoints:\n";
echo "  /main     - Main menu\n";
echo "  /customer - Customer service\n";
echo "  /product  - Product information\n";

$service->run();
