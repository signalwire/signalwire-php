# REST Namespaces Reference (PHP)

## Overview

The `RestClient` provides namespaced sub-objects for every SignalWire API
surface. Namespaces and their sub-resources are accessed via method calls, and
CRUD methods (`create`, `update`, `search`, ...) take a single associative
array.

## Namespace Map

<!-- snippet: no-compile namespace map: `...` is an illustrative placeholder for the CRUD calls, not runnable PHP -->
```php
$client->fabric()->...           // Fabric API (AI agents, SWML, subscribers, etc.)
$client->calling()->...          // REST call control commands
$client->phoneNumbers()->...     // Phone number management
$client->video()->...            // Video rooms, sessions, recordings
$client->datasphere()->...       // Documents and semantic search
$client->registry()->...         // 10DLC brands, campaigns, orders, numbers
$client->queues()->...           // Call queues
$client->recordings()->...       // Call recordings
$client->mfa()->...              // Multi-factor authentication
$client->numberGroups()->...     // Phone number groups
$client->lookup()->...           // Phone number lookup
$client->verifiedCallers()->...  // Verified caller IDs
$client->sipProfile()->...       // SIP profile management
$client->shortCodes()->...       // Short codes
$client->addresses()->...        // Addresses
$client->importedNumbers()->...  // Imported numbers
$client->logs()->...             // Call, message, fax, conference logs
$client->project()->...          // Project-level tokens
$client->pubsub()->...           // PubSub tokens
$client->chat()->...             // Chat tokens
```

## Phone Numbers

```php
// Search
$available = $client->phoneNumbers()->search(['areacode' => '512', 'max_results' => 5]);

// Purchase
$number = $client->phoneNumbers()->create(['number' => '+15125551234']);

// List / Get / Update / Delete
$numbers = $client->phoneNumbers()->list();
$detail  = $client->phoneNumbers()->get($numId);
$client->phoneNumbers()->update($numId, ['name' => 'Main Line']);
$client->phoneNumbers()->delete($numId);
```

## Number Groups

```php
$group = $client->numberGroups()->create(['name' => 'Sales Pool']);
$client->numberGroups()->addMembership($groupId, ['phone_number_id' => $numId]);
$members = $client->numberGroups()->listMemberships($groupId);
$client->numberGroups()->delete($groupId);
```

## Datasphere

```php
// Upload document
$doc = $client->datasphere()->documents()->create([
    'url'  => 'https://example.com/document.txt',
    'tags' => ['support'],
]);

// Check status
$status = $client->datasphere()->documents()->get($docId);

// List chunks
$chunks = $client->datasphere()->documents()->listChunks($docId);

// Semantic search
$results = $client->datasphere()->documents()->search([
    'query_string' => 'how to reset password',
    'count'        => 5,
]);

// Delete
$client->datasphere()->documents()->delete($docId);
```

## Video

```php
// Rooms
$room = $client->video()->rooms()->create(['name' => 'standup', 'max_members' => 10]);
$rooms = $client->video()->rooms()->list();
$client->video()->rooms()->delete($roomId);

// Room tokens
$token = $client->video()->roomTokens()->create([
    'room_name'   => 'standup',
    'user_name'   => 'alice',
    'permissions' => ['room.self.audio_mute'],
]);

// Sessions
$sessions = $client->video()->roomSessions()->list();
$members  = $client->video()->roomSessions()->listMembers($sessionId);

// Recordings
$recordings = $client->video()->roomRecordings()->list();

// Conferences
$conf = $client->video()->conferences()->createStream($confId, ['url' => 'rtmp://live.example.com/key']);

// Streams
$client->video()->streams()->update($streamId, ['url' => 'rtmp://backup.example.com/key']);
$client->video()->streams()->delete($streamId);
```

## Queues

```php
$queue   = $client->queues()->create(['name' => 'Support Queue', 'max_size' => 50]);
$queues  = $client->queues()->list();
$members = $client->queues()->listMembers($queueId);
$next    = $client->queues()->getNextMember($queueId);
$client->queues()->delete($queueId);
```

## Recordings

```php
$recordings = $client->recordings()->list();
$detail     = $client->recordings()->get($recId);
$client->recordings()->delete($recId);
```

## MFA

```php
// Send via SMS
$sms = $client->mfa()->sms([
    'to'           => '+15551234567',
    'from'         => '+15559876543',
    'message'      => 'Your code is {{code}}',
    'token_length' => 6,
]);

// Send via voice call
$voice = $client->mfa()->call([
    'to'           => '+15551234567',
    'from'         => '+15559876543',
    'message'      => 'Your code is {{code}}',
    'token_length' => 6,
]);

// Verify
$result = $client->mfa()->verify($requestId, ['token' => '123456']);
```

## Lookup

```php
$info = $client->lookup()->phoneNumber('+15125551234');
echo "Carrier: " . ($info['carrier']['name'] ?? 'unknown') . "\n";
```

## Verified Callers

```php
$caller = $client->verifiedCallers()->create(['phone_number' => '+15125559999']);
$client->verifiedCallers()->submitVerification($callerId, ['verification_code' => '123456']);
$client->verifiedCallers()->delete($callerId);
```

## SIP Profile

```php
$profile = $client->sipProfile()->get();
$client->sipProfile()->update(['default_codecs' => ['PCMU', 'PCMA']]);
```

## Logs

```php
$messageLogs    = $client->logs()->messages()->list();
$voiceLogs      = $client->logs()->voice()->list();
$voiceDetail    = $client->logs()->voice()->get($logId);
$voiceEvents    = $client->logs()->voice()->listEvents($logId);
$faxLogs        = $client->logs()->fax()->list();
$conferenceLogs = $client->logs()->conferences()->list();
```

## Project Tokens

```php
$token = $client->project()->tokens()->create([
    'name'        => 'CI Token',
    'permissions' => ['calling', 'messaging', 'video'],
]);
$client->project()->tokens()->update($tokenId, ['name' => 'Updated Token']);
$client->project()->tokens()->delete($tokenId);
```

## PubSub and Chat Tokens

```php
$client->pubsub()->createToken([
    'channels' => ['notifications' => ['read' => true, 'write' => true]],
    'ttl'      => 3600,
]);

$client->chat()->createToken([
    'member_id' => 'user-alice',
    'channels'  => ['general' => ['read' => true, 'write' => true]],
    'ttl'       => 3600,
]);
```
