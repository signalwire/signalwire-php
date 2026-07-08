<?php
/**
 * Dynamic SWML Service Example
 *
 * Creates a SWML service that generates different responses based on
 * POST data. Demonstrates subclassing SWMLService and overriding the
 * onSwmlRequest() customization hook to build the document dynamically.
 *
 * Python parity: examples/dynamic_swml_service.py defines
 * DynamicGreetingService(SWMLService) and overrides on_request().
 */

require 'vendor/autoload.php';

use SignalWire\SWML\Service as SWMLService;

/**
 * A service that customizes its greeting based on POST data.
 */
final class DynamicGreetingService extends SWMLService
{
    public function __construct()
    {
        parent::__construct(name: 'dynamic-greeting', route: '/greeting');

        // Build the default SWML document with a generic greeting.
        $this->resetDocument();
        $this->answer();
        $this->addVerb('play', ['url' => 'say:Hello, thank you for calling our service.']);
        $this->addVerb('prompt', [
            'play'        => 'say:Please press 1 for sales, 2 for support, or 3 to leave a message.',
            'max_digits'  => 1,
            'terminators' => '#',
        ]);
        $this->hangup();
    }

    /**
     * Customize the SWML document based on the request data. Returning
     * null uses the document that was just (re)built.
     *
     * @param array<string, mixed>|null $requestData
     * @return array<string, mixed>|null
     */
    public function onSwmlRequest(?array $requestData = null, ?string $callbackPath = null): ?array
    {
        if (!$requestData) {
            return null;
        }

        $this->resetDocument();
        $this->answer();

        $callerName = $requestData['caller_name'] ?? null;
        if ($callerName) {
            $this->addVerb('play', ['url' => "say:Hello {$callerName}, welcome back to our service!"]);
        } else {
            $this->addVerb('play', ['url' => 'say:Hello, thank you for calling our service.']);
        }

        $callerType = strtolower($requestData['caller_type'] ?? '');

        if ($callerType === 'vip') {
            $this->addVerb('play', ['url' => "say:As a VIP customer, you'll be connected to our priority support team."]);
            $this->addVerb('connect', ['to' => '+15551234567', 'timeout' => 30, 'answer_on_bridge' => true]);
        } elseif ($callerType === 'existing') {
            $this->addVerb('prompt', [
                'play'        => 'say:Please press 1 for account management, 2 for technical support, or 3 for billing.',
                'max_digits'  => 1,
                'terminators' => '#',
            ]);
        } else {
            $this->addVerb('prompt', [
                'play'        => 'say:Please press 1 for sales, 2 for support, or 3 to leave a message.',
                'max_digits'  => 1,
                'terminators' => '#',
            ]);
        }

        $department = strtolower($requestData['department'] ?? '');
        if ($department) {
            $numbers = [
                'sales'     => '+15551112222',
                'support'   => '+15553334444',
                'billing'   => '+15555556666',
                'technical' => '+15557778888',
            ];
            $this->addVerb('play', ['url' => "say:We'll connect you to our {$department} department."]);
            $this->addVerb('connect', [
                'to'      => $numbers[$department] ?? '+15559990000',
                'timeout' => 30,
            ]);
        }

        $this->hangup();

        return null;
    }
}

$greeting = new DynamicGreetingService();

echo "Starting Dynamic Greeting Service\n";
echo "Available at: http://localhost:3000{$greeting->getRoute()}\n";
echo "\nSend POST with JSON:\n";
echo json_encode([
    'caller_name' => 'John Doe',
    'caller_type' => 'vip',
    'department'  => 'technical',
], JSON_PRETTY_PRINT) . "\n\n";

$greeting->run();
