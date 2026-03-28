<?php
/**
 * Example: Upload a document to Datasphere and run a semantic search.
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

// 1. Upload a document
echo "Uploading document to Datasphere...\n";
$doc = $client->datasphere->documents->create(
    url:  'https://filesamples.com/samples/document/txt/sample3.txt',
    tags: ['support', 'demo'],
);
$docId = $doc['id'];
echo "  Document created: {$docId} (status: " . ($doc['status'] ?? 'unknown') . ")\n";

// 2. Wait for vectorization to complete
echo "\nWaiting for document to be vectorized...\n";
for ($i = 1; $i <= 30; $i++) {
    sleep(2);
    $docStatus = $client->datasphere->documents->get($docId);
    $status = $docStatus['status'] ?? 'unknown';
    echo "  Poll {$i}: status={$status}\n";

    if ($status === 'completed') {
        echo "  Vectorized! Chunks: " . ($docStatus['number_of_chunks'] ?? 0) . "\n";
        break;
    }
    if ($status === 'error' || $status === 'failed') {
        echo "  Document processing failed: {$status}\n";
        $client->datasphere->documents->delete($docId);
        exit(1);
    }

    if ($i === 30) {
        echo "  Timed out waiting for vectorization.\n";
        $client->datasphere->documents->delete($docId);
        exit(1);
    }
}

// 3. List chunks
echo "\nListing chunks for document {$docId}...\n";
$chunks = $client->datasphere->documents->listChunks($docId);
$chunkList = $chunks['data'] ?? [];
foreach (array_slice($chunkList, 0, 5) as $chunk) {
    $content = $chunk['content'] ?? '';
    if (strlen($content) > 80) {
        $content = substr($content, 0, 80) . '...';
    }
    echo "  - Chunk {$chunk['id']}: {$content}\n";
}

// 4. Semantic search
echo "\nSearching Datasphere...\n";
$results = $client->datasphere->documents->search(
    queryString: 'lorem ipsum dolor sit amet',
    count:       3,
);
foreach (($results['chunks'] ?? []) as $chunk) {
    $text = $chunk['text'] ?? '';
    if (strlen($text) > 100) {
        $text = substr($text, 0, 100) . '...';
    }
    echo "  - {$text}\n";
}

// 5. Clean up
echo "\nDeleting document {$docId}...\n";
$client->datasphere->documents->delete($docId);
echo "  Deleted.\n";
