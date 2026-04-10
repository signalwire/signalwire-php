<?php
/**
 * Receptionist Agent Example
 *
 * Uses the ReceptionistAgent prefab to create a call routing system
 * with departments, custom greeting, and summary handling.
 */

require 'vendor/autoload.php';

use SignalWire\Prefabs\ReceptionistAgent;

$departments = [
    ['name' => 'sales',   'description' => 'For product inquiries, pricing, and purchasing',                     'number' => '+15551235555'],
    ['name' => 'support', 'description' => 'For technical assistance, troubleshooting, and bug reports',         'number' => '+15551236666'],
    ['name' => 'billing', 'description' => 'For payment questions, invoices, and subscription changes',          'number' => '+15551237777'],
    ['name' => 'general', 'description' => "For all other inquiries or if you're not sure which department",     'number' => '+15551238888'],
];

$agent = new ReceptionistAgent(
    name:        'acme-receptionist',
    departments: $departments,
    route:       '/reception',
    greeting:    'Hello, thank you for calling ACME Corporation. How may I direct your call today?',
);

$agent->promptAddSection(
    'Company Information',
    'ACME Corporation is a leading provider of innovative solutions. '
    . 'Our business hours are Monday through Friday, 9 AM to 5 PM Eastern Time.',
);

$deptText = "Available departments for transfer:\n";
foreach ($departments as $dept) {
    $deptText .= "- " . ucfirst($dept['name']) . ": {$dept['description']}\n";
}
$agent->promptAddSection('Transfer Options', $deptText);

$agent->onSummary(function ($summary, $raw) {
    if ($summary) {
        echo "Call Summary: " . json_encode($summary, JSON_PRETTY_PRINT) . "\n";

        if (is_array($summary) && ($summary['satisfaction'] ?? '') === 'low') {
            echo "ALERT: Caller had low satisfaction. Schedule follow-up.\n";
        }
    }
});

$user = $agent->basicAuthUser();
$pass = $agent->basicAuthPassword();

echo "Starting Receptionist Agent\n";
echo "URL: http://localhost:3000/reception\n";
echo "Basic Auth: {$user}:{$pass}\n";

$agent->run();
