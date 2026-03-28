# Fabric Resources (PHP)

## Overview

The Fabric API manages AI agents, SWML scripts, subscribers, call flows, conference rooms, SIP gateways, and more. Access it via `$client->fabric`.

## AI Agents

```php
// Create
$agent = $client->fabric->aiAgents->create(
    name:   'Support Bot',
    prompt: ['text' => 'You are a helpful support agent.'],
);
$agentId = $agent['id'];

// List
$agents = $client->fabric->aiAgents->list();
foreach (($agents['data'] ?? []) as $a) {
    echo "- {$a['id']}: " . ($a['name'] ?? 'unnamed') . "\n";
}

// Get / Update / Delete
$detail = $client->fabric->aiAgents->get($agentId);
$client->fabric->aiAgents->update($agentId, name: 'Updated Bot');
$client->fabric->aiAgents->delete($agentId);
```

## SWML Scripts

```php
$swml = $client->fabric->swmlScripts->create(
    name:     'Greeting Script',
    contents: [
        'sections' => [
            'main' => [['play' => ['url' => 'say:Hello from SignalWire']]],
        ],
    ],
);
$swmlId = $swml['id'];

$scripts = $client->fabric->swmlScripts->list();
$client->fabric->swmlScripts->delete($swmlId);
```

## SWML Webhooks

```php
$webhook = $client->fabric->swmlWebhooks->create(
    name:              'External Handler',
    primaryRequestUrl: 'https://example.com/swml-handler',
);
$client->fabric->swmlWebhooks->delete($webhook['id']);
```

## Call Flows

```php
// Create
$flow = $client->fabric->callFlows->create(title: 'Main IVR Flow');
$flowId = $flow['id'];

// Deploy a version
$client->fabric->callFlows->deployVersion($flowId, label: 'v1');

// List versions
$versions = $client->fabric->callFlows->listVersions($flowId);

// List addresses
$addrs = $client->fabric->callFlows->listAddresses($flowId);

$client->fabric->callFlows->delete($flowId);
```

## Subscribers

```php
// Create subscriber
$subscriber = $client->fabric->subscribers->create(
    name:  'Alice Johnson',
    email: 'alice@example.com',
);
$subId = $subscriber['id'];

// Add SIP endpoint
$endpoint = $client->fabric->subscribers->createSipEndpoint($subId,
    username: 'alice_sip',
    password: 'SecurePass123!',
);
$epId = $endpoint['id'];

// List / Get SIP endpoints
$endpoints = $client->fabric->subscribers->listSipEndpoints($subId);
$detail    = $client->fabric->subscribers->getSipEndpoint($subId, $epId);

// Clean up
$client->fabric->subscribers->deleteSipEndpoint($subId, $epId);
$client->fabric->subscribers->delete($subId);
```

## SIP Gateways

```php
$gateway = $client->fabric->sipGateways->create(
    name:       'Office PBX Gateway',
    uri:        'sip:pbx.example.com',
    encryption: 'required',
    ciphers:    ['AES_256_CM_HMAC_SHA1_80'],
    codecs:     ['PCMU', 'PCMA'],
);
$client->fabric->sipGateways->delete($gateway['id']);
```

## Conference Rooms

```php
$room = $client->fabric->conferenceRooms->create(name: 'team-standup');
$addrs = $client->fabric->conferenceRooms->listAddresses($room['id']);
$client->fabric->conferenceRooms->delete($room['id']);
```

## cXML Scripts and Webhooks

```php
$cxml = $client->fabric->cxmlScripts->create(
    name:     'Hold Music',
    contents: '<Response><Say>Please hold.</Say></Response>',
);

$cxmlWh = $client->fabric->cxmlWebhooks->create(
    name:              'External cXML Handler',
    primaryRequestUrl: 'https://example.com/cxml-handler',
);

$client->fabric->cxmlWebhooks->delete($cxmlWh['id']);
$client->fabric->cxmlScripts->delete($cxml['id']);
```

## Relay Applications

```php
$app = $client->fabric->relayApplications->create(
    name:  'Inbound Handler',
    topic: 'office',
);
$client->fabric->relayApplications->delete($app['id']);
```

## Generic Resources

```php
$resources = $client->fabric->resources->list();
$detail    = $client->fabric->resources->get($resourceId);

// Assign routes
$client->fabric->resources->assignPhoneRoute($resourceId, phoneNumber: '+15551234567');
$client->fabric->resources->assignDomainApplication($resourceId, domain: 'app.example.com');
```

## Fabric Addresses

```php
$addresses = $client->fabric->addresses->list();
$detail    = $client->fabric->addresses->get($addressId);
```

## Tokens

```php
// Guest token
$guest = $client->fabric->tokens->createGuestToken(resourceId: $resourceId);

// Invite token
$invite = $client->fabric->tokens->createInviteToken(resourceId: $resourceId);

// Embed token
$embed = $client->fabric->tokens->createEmbedToken(resourceId: $resourceId);

// Subscriber token
$sub = $client->fabric->tokens->createSubscriberToken(
    subscriberId: $subscriberId,
    reference:    $subscriberId,
);
```
