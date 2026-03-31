<?php
/**
 * Advanced DataMap Features Demo
 *
 * Demonstrates all comprehensive DataMap features including:
 * - Expressions with patterns
 * - Advanced webhook features (form_param, input_args_as_params, require_args)
 * - Post-webhook expressions
 * - Form parameter encoding
 * - Fallback chains
 */

require 'vendor/autoload.php';

use SignalWire\DataMap\DataMap;
use SignalWire\SWAIG\FunctionResult;

// 1. Expression-based responses with pattern matching
$commandProcessor = (new DataMap('command_processor'))
    ->description('Process user commands with pattern matching')
    ->parameter('command', 'string', 'User command to process', required: true)
    ->parameter('target', 'string', 'Optional target for the command')
    ->expression(
        '${args.command}',
        '^start',
        new FunctionResult('Starting process: ${args.target}'),
    )
    ->expression(
        '${args.command}',
        '^stop',
        new FunctionResult('Stopping process: ${args.target}'),
    )
    ->expression(
        '${args.command}',
        '^status',
        new FunctionResult('Checking status of: ${args.target}'),
        nomatchOutput: new FunctionResult('Unknown command: ${args.command}. Try start, stop, or status.'),
    );

echo "=== Expression Demo ===\n";
echo json_encode($commandProcessor->toSwaigFunction(), JSON_PRETTY_PRINT) . "\n\n";

// 2. Advanced webhook features
$advancedApi = (new DataMap('advanced_api_tool'))
    ->description('API tool with advanced webhook features')
    ->parameter('action', 'string', 'Action to perform', required: true)
    ->parameter('data', 'string', 'Data to send')
    ->parameter('format', 'string', 'Response format')
    ->webhook('POST', 'https://api.example.com/advanced', headers: [
        'Authorization' => 'Bearer ${token}',
        'User-Agent'    => 'SignalWire-Agent/1.0',
    ])
    ->output(new FunctionResult('Result: ${response.data}'))
    ->webhook('GET', 'https://backup-api.example.com/simple', headers: [
        'Accept' => 'application/json',
    ])
    ->params(['q' => '${args.action}'])
    ->output(new FunctionResult('Backup result: ${response.data}'));

echo "=== Advanced Webhook Demo ===\n";
echo json_encode($advancedApi->toSwaigFunction(), JSON_PRETTY_PRINT) . "\n\n";

// 3. Form parameter encoding
$formSubmission = (new DataMap('form_submission_tool'))
    ->description('Submit form data using form encoding')
    ->parameter('name', 'string', 'User name', required: true)
    ->parameter('email', 'string', 'User email', required: true)
    ->parameter('message', 'string', 'Message content', required: true)
    ->webhook('POST', 'https://forms.example.com/submit', headers: [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'X-API-Key'    => '${api_key}',
    ])
    ->params([
        'name'    => '${args.name}',
        'email'   => '${args.email}',
        'message' => '${args.message}',
    ])
    ->output(new FunctionResult('Form submitted successfully for ${args.name}'));

echo "=== Form Encoding Demo ===\n";
echo json_encode($formSubmission->toSwaigFunction(), JSON_PRETTY_PRINT) . "\n\n";

// 4. Array processing with foreach
$searchResults = (new DataMap('search_results_tool'))
    ->description('Search and format results from API')
    ->parameter('query', 'string', 'Search query', required: true)
    ->parameter('limit', 'string', 'Maximum results')
    ->webhook('GET', 'https://search-api.example.com/search', headers: [
        'Authorization' => 'Bearer ${search_token}',
    ])
    ->params([
        'q'           => '${args.query}',
        'max_results' => '${args.limit}',
    ])
    ->output(new FunctionResult('Search results for "${args.query}": ${response.results}'));

echo "=== Array Processing Demo ===\n";
echo json_encode($searchResults->toSwaigFunction(), JSON_PRETTY_PRINT) . "\n\n";

// 5. Conditional logic with expressions
$smartCalculator = (new DataMap('smart_calculator'))
    ->description('Smart calculator with conditional responses')
    ->parameter('expression', 'string', 'Mathematical expression', required: true)
    ->parameter('format', 'string', 'Output format (simple/detailed)')
    ->expression(
        '${args.expression}',
        '^\s*\d+\s*[+\-*/]\s*\d+\s*$',
        new FunctionResult('Quick calculation: ${args.expression}'),
    )
    ->expression(
        '${args.format}',
        '^detailed$',
        new FunctionResult('Detailed: ${args.expression}'),
        nomatchOutput: new FunctionResult('Expression: ${args.expression}'),
    );

echo "=== Conditional Logic Demo ===\n";
echo json_encode($smartCalculator->toSwaigFunction(), JSON_PRETTY_PRINT) . "\n";
