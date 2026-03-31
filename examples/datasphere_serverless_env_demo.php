<?php
/**
 * DataSphere Serverless Environment Demo
 *
 * Loads the DataSphere Serverless skill from environment variables,
 * showing best practices for production deployment.
 *
 * Required environment variables:
 *   SIGNALWIRE_SPACE_NAME   - Your SignalWire space name
 *   SIGNALWIRE_PROJECT_ID   - Your SignalWire project ID
 *   SIGNALWIRE_TOKEN        - Your SignalWire authentication token
 *   DATASPHERE_DOCUMENT_ID  - The DataSphere document ID to search
 *
 * Optional:
 *   DATASPHERE_COUNT        - Number of search results (default: 3)
 *   DATASPHERE_DISTANCE     - Search distance threshold (default: 4.0)
 *   DATASPHERE_TAGS         - Comma-separated list of tags
 *   DATASPHERE_LANGUAGE     - Language code (e.g. "en")
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

function requireEnv(string $name): string
{
    $value = $_ENV[$name] ?? getenv($name);
    if (!$value) {
        echo "Error: Required environment variable {$name} is not set\n\n";
        echo "Required:\n";
        echo "  SIGNALWIRE_SPACE_NAME, SIGNALWIRE_PROJECT_ID, SIGNALWIRE_TOKEN, DATASPHERE_DOCUMENT_ID\n";
        echo "Optional:\n";
        echo "  DATASPHERE_COUNT, DATASPHERE_DISTANCE, DATASPHERE_TAGS, DATASPHERE_LANGUAGE\n";
        exit(1);
    }
    return $value;
}

$spaceName  = requireEnv('SIGNALWIRE_SPACE_NAME');
$projectId  = requireEnv('SIGNALWIRE_PROJECT_ID');
$token      = requireEnv('SIGNALWIRE_TOKEN');
$documentId = requireEnv('DATASPHERE_DOCUMENT_ID');

$count    = (int) ($_ENV['DATASPHERE_COUNT'] ?? getenv('DATASPHERE_COUNT') ?: 3);
$distance = (float) ($_ENV['DATASPHERE_DISTANCE'] ?? getenv('DATASPHERE_DISTANCE') ?: 4.0);
$tagsRaw  = $_ENV['DATASPHERE_TAGS'] ?? getenv('DATASPHERE_TAGS') ?: '';
$tags     = $tagsRaw ? array_map('trim', explode(',', $tagsRaw)) : [];
$language = $_ENV['DATASPHERE_LANGUAGE'] ?? getenv('DATASPHERE_LANGUAGE') ?: null;

$agent = new AgentBase(name: 'DataSphere Serverless Env Demo', route: '/ds-env');
$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

$skillConfig = [
    'space_name'  => $spaceName,
    'project_id'  => $projectId,
    'token'       => $token,
    'document_id' => $documentId,
    'count'       => $count,
    'distance'    => $distance,
];
if ($tags) {
    $skillConfig['tags'] = $tags;
}
if ($language) {
    $skillConfig['language'] = $language;
}

try {
    $agent->addSkill('datasphere_serverless', $skillConfig);
    echo "Added DataSphere Serverless skill\n";
    echo "  Document ID: {$documentId}\n";
    echo "  Count: {$count}, Distance: {$distance}\n";
    if ($tags) echo "  Tags: " . implode(', ', $tags) . "\n";
    if ($language) echo "  Language: {$language}\n";
} catch (\Exception $e) {
    echo "Failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nStarting DataSphere Serverless Env Demo\n";
echo "Available at: http://localhost:3000/ds-env\n";

$agent->run();
