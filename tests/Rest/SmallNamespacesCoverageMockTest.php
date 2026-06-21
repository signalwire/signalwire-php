<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\REST\RestClient;
use SignalWire\REST\SignalWireRestError;

/**
 * Full success+error coverage matrix for the SMALL-NAMESPACES group:
 *   datasphere, chat, pubsub, calling, message/fax/voice/conference logs,
 *   project tokens. 23 routes, each with a 2xx success test and a 4xx/5xx
 *   error test.
 */
class SmallNamespacesCoverageMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;
    private string $project;

    protected function setUp(): void
    {
        [$this->client, $this->mock, $this->project] = MockTest::scopedClient();
    }

    // ===== calling.call-commands ======================================

    #[Test]
    public function callingCommandSuccess(): void
    {
        $body = $this->client->calling()->dial([
            'url' => 'https://example.com/swml',
            'to' => '+15551234567',
        ]);
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/calling/calls', $j->path);
        $this->assertSame('calling.call-commands', $j->matchedRoute);
    }

    #[Test]
    public function callingCommandError(): void
    {
        $this->mock->scenarios()->set('calling.call-commands', 422, ['error' => 'bad']);
        try {
            $this->client->calling()->dial(['url' => 'https://example.com/swml']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('calling.call-commands', $j->matchedRoute);
    }

    // ===== chat.create_chat_token =====================================

    #[Test]
    public function chatCreateTokenSuccess(): void
    {
        $body = $this->client->chat()->createToken(['ttl' => 60]);
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/chat/tokens', $j->path);
        $this->assertSame('chat.create_chat_token', $j->matchedRoute);
    }

    #[Test]
    public function chatCreateTokenError(): void
    {
        $this->mock->scenarios()->set('chat.create_chat_token', 422, ['error' => 'bad']);
        try {
            $this->client->chat()->createToken(['ttl' => 60]);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('chat.create_chat_token', $j->matchedRoute);
    }

    // ===== pubsub.create_token ========================================

    #[Test]
    public function pubsubCreateTokenSuccess(): void
    {
        $body = $this->client->pubsub()->createToken(['ttl' => 60]);
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/pubsub/tokens', $j->path);
        $this->assertSame('pubsub.create_token', $j->matchedRoute);
    }

    #[Test]
    public function pubsubCreateTokenError(): void
    {
        $this->mock->scenarios()->set('pubsub.create_token', 422, ['error' => 'bad']);
        try {
            $this->client->pubsub()->createToken(['ttl' => 60]);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('pubsub.create_token', $j->matchedRoute);
    }

    // ===== datasphere.create_document =================================

    #[Test]
    public function datasphereCreateDocumentSuccess(): void
    {
        $body = $this->client->datasphere()->documents()->create(['name' => 'doc']);
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/datasphere/documents', $j->path);
        $this->assertSame('datasphere.create_document', $j->matchedRoute);
    }

    #[Test]
    public function datasphereCreateDocumentError(): void
    {
        $this->mock->scenarios()->set('datasphere.create_document', 422, ['error' => 'bad']);
        try {
            $this->client->datasphere()->documents()->create(['name' => 'doc']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('datasphere.create_document', $j->matchedRoute);
    }

    // ===== datasphere.list_documents ==================================

    #[Test]
    public function datasphereListDocumentsSuccess(): void
    {
        $body = $this->client->datasphere()->documents()->list();
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/datasphere/documents', $j->path);
        $this->assertSame('datasphere.list_documents', $j->matchedRoute);
    }

    #[Test]
    public function datasphereListDocumentsError(): void
    {
        $this->mock->scenarios()->set('datasphere.list_documents', 500, ['error' => 'boom']);
        try {
            $this->client->datasphere()->documents()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('datasphere.list_documents', $j->matchedRoute);
    }

    // ===== datasphere.get_document ====================================

    #[Test]
    public function datasphereGetDocumentSuccess(): void
    {
        $body = $this->client->datasphere()->documents()->get('doc-1');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/datasphere/documents/doc-1', $j->path);
        $this->assertSame('datasphere.get_document', $j->matchedRoute);
    }

    #[Test]
    public function datasphereGetDocumentError(): void
    {
        $this->mock->scenarios()->set('datasphere.get_document', 404, ['error' => 'nope']);
        try {
            $this->client->datasphere()->documents()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('datasphere.get_document', $j->matchedRoute);
    }

    // ===== datasphere.update_document (PATCH) =========================

    #[Test]
    public function datasphereUpdateDocumentSuccess(): void
    {
        $body = $this->client->datasphere()->documents()->update('doc-1', ['name' => 'renamed']);
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('PATCH', $j->method);
        $this->assertSame('/api/datasphere/documents/doc-1', $j->path);
        $this->assertSame('datasphere.update_document', $j->matchedRoute);
    }

    #[Test]
    public function datasphereUpdateDocumentError(): void
    {
        $this->mock->scenarios()->set('datasphere.update_document', 404, ['error' => 'nope']);
        try {
            $this->client->datasphere()->documents()->update('missing', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('datasphere.update_document', $j->matchedRoute);
    }

    // ===== datasphere.delete_document =================================

    #[Test]
    public function datasphereDeleteDocumentSuccess(): void
    {
        $body = $this->client->datasphere()->documents()->delete('doc-1');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame('/api/datasphere/documents/doc-1', $j->path);
        $this->assertSame('datasphere.delete_document', $j->matchedRoute);
    }

    #[Test]
    public function datasphereDeleteDocumentError(): void
    {
        $this->mock->scenarios()->set('datasphere.delete_document', 404, ['error' => 'nope']);
        try {
            $this->client->datasphere()->documents()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('datasphere.delete_document', $j->matchedRoute);
    }

    // ===== datasphere.search_documents ================================

    #[Test]
    public function datasphereSearchDocumentsSuccess(): void
    {
        $body = $this->client->datasphere()->documents()->search(['query' => 'hello']);
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/datasphere/documents/search', $j->path);
        $this->assertSame('datasphere.search_documents', $j->matchedRoute);
    }

    #[Test]
    public function datasphereSearchDocumentsError(): void
    {
        $this->mock->scenarios()->set('datasphere.search_documents', 422, ['error' => 'bad']);
        try {
            $this->client->datasphere()->documents()->search(['query' => 'hello']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('datasphere.search_documents', $j->matchedRoute);
    }

    // ===== datasphere.list_document_chunks ============================

    #[Test]
    public function datasphereListChunksSuccess(): void
    {
        $body = $this->client->datasphere()->documents()->listChunks('doc-1');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/datasphere/documents/doc-1/chunks', $j->path);
        $this->assertSame('datasphere.list_document_chunks', $j->matchedRoute);
    }

    #[Test]
    public function datasphereListChunksError(): void
    {
        $this->mock->scenarios()->set('datasphere.list_document_chunks', 500, ['error' => 'boom']);
        try {
            $this->client->datasphere()->documents()->listChunks('doc-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('datasphere.list_document_chunks', $j->matchedRoute);
    }

    // ===== datasphere.get_document_chunk ==============================

    #[Test]
    public function datasphereGetChunkSuccess(): void
    {
        $body = $this->client->datasphere()->documents()->getChunk('doc-1', 'chunk-9');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/datasphere/documents/doc-1/chunks/chunk-9', $j->path);
        $this->assertSame('datasphere.get_document_chunk', $j->matchedRoute);
    }

    #[Test]
    public function datasphereGetChunkError(): void
    {
        $this->mock->scenarios()->set('datasphere.get_document_chunk', 404, ['error' => 'nope']);
        try {
            $this->client->datasphere()->documents()->getChunk('doc-1', 'missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('datasphere.get_document_chunk', $j->matchedRoute);
    }

    // ===== datasphere.delete_document_chunk ===========================

    #[Test]
    public function datasphereDeleteChunkSuccess(): void
    {
        $body = $this->client->datasphere()->documents()->deleteChunk('doc-1', 'chunk-9');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame('/api/datasphere/documents/doc-1/chunks/chunk-9', $j->path);
        $this->assertSame('datasphere.delete_document_chunk', $j->matchedRoute);
    }

    #[Test]
    public function datasphereDeleteChunkError(): void
    {
        $this->mock->scenarios()->set('datasphere.delete_document_chunk', 404, ['error' => 'nope']);
        try {
            $this->client->datasphere()->documents()->deleteChunk('doc-1', 'missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('datasphere.delete_document_chunk', $j->matchedRoute);
    }

    // ===== message.list_message_logs ==================================

    #[Test]
    public function messageListLogsSuccess(): void
    {
        $body = $this->client->logs()->messages()->list();
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/messaging/logs', $j->path);
        $this->assertSame('message.list_message_logs', $j->matchedRoute);
    }

    #[Test]
    public function messageListLogsError(): void
    {
        $this->mock->scenarios()->set('message.list_message_logs', 500, ['error' => 'boom']);
        try {
            $this->client->logs()->messages()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('message.list_message_logs', $j->matchedRoute);
    }

    // ===== message.get_message_log ====================================

    #[Test]
    public function messageGetLogSuccess(): void
    {
        $body = $this->client->logs()->messages()->get('ml-42');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/messaging/logs/ml-42', $j->path);
        $this->assertSame('message.get_message_log', $j->matchedRoute);
    }

    #[Test]
    public function messageGetLogError(): void
    {
        $this->mock->scenarios()->set('message.get_message_log', 404, ['error' => 'nope']);
        try {
            $this->client->logs()->messages()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('message.get_message_log', $j->matchedRoute);
    }

    // ===== fax.list_fax_logs ==========================================

    #[Test]
    public function faxListLogsSuccess(): void
    {
        $body = $this->client->logs()->fax()->list();
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/fax/logs', $j->path);
        $this->assertSame('fax.list_fax_logs', $j->matchedRoute);
    }

    #[Test]
    public function faxListLogsError(): void
    {
        $this->mock->scenarios()->set('fax.list_fax_logs', 500, ['error' => 'boom']);
        try {
            $this->client->logs()->fax()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('fax.list_fax_logs', $j->matchedRoute);
    }

    // ===== fax.get_fax_log ============================================

    #[Test]
    public function faxGetLogSuccess(): void
    {
        $body = $this->client->logs()->fax()->get('fl-7');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/fax/logs/fl-7', $j->path);
        $this->assertSame('fax.get_fax_log', $j->matchedRoute);
    }

    #[Test]
    public function faxGetLogError(): void
    {
        $this->mock->scenarios()->set('fax.get_fax_log', 404, ['error' => 'nope']);
        try {
            $this->client->logs()->fax()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('fax.get_fax_log', $j->matchedRoute);
    }

    // ===== voice.list_voice_logs ======================================

    #[Test]
    public function voiceListLogsSuccess(): void
    {
        $body = $this->client->logs()->voice()->list();
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/voice/logs', $j->path);
        $this->assertSame('voice.list_voice_logs', $j->matchedRoute);
    }

    #[Test]
    public function voiceListLogsError(): void
    {
        $this->mock->scenarios()->set('voice.list_voice_logs', 500, ['error' => 'boom']);
        try {
            $this->client->logs()->voice()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('voice.list_voice_logs', $j->matchedRoute);
    }

    // ===== voice.get_voice_log ========================================

    #[Test]
    public function voiceGetLogSuccess(): void
    {
        $body = $this->client->logs()->voice()->get('vl-99');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/voice/logs/vl-99', $j->path);
        $this->assertSame('voice.get_voice_log', $j->matchedRoute);
    }

    #[Test]
    public function voiceGetLogError(): void
    {
        $this->mock->scenarios()->set('voice.get_voice_log', 404, ['error' => 'nope']);
        try {
            $this->client->logs()->voice()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('voice.get_voice_log', $j->matchedRoute);
    }

    // ===== voice.list_voice_log_events ================================

    #[Test]
    public function voiceListLogEventsSuccess(): void
    {
        $body = $this->client->logs()->voice()->listEvents('vl-99');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/voice/logs/vl-99/events', $j->path);
        $this->assertSame('voice.list_voice_log_events', $j->matchedRoute);
    }

    #[Test]
    public function voiceListLogEventsError(): void
    {
        $this->mock->scenarios()->set('voice.list_voice_log_events', 404, ['error' => 'nope']);
        try {
            $this->client->logs()->voice()->listEvents('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('voice.list_voice_log_events', $j->matchedRoute);
    }

    // ===== logs.list_conferences ======================================

    #[Test]
    public function conferencesListSuccess(): void
    {
        $body = $this->client->logs()->conferences()->list();
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/logs/conferences', $j->path);
        $this->assertSame('logs.list_conferences', $j->matchedRoute);
    }

    #[Test]
    public function conferencesListError(): void
    {
        $this->mock->scenarios()->set('logs.list_conferences', 500, ['error' => 'boom']);
        try {
            $this->client->logs()->conferences()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('logs.list_conferences', $j->matchedRoute);
    }

    // ===== project.create_token =======================================

    #[Test]
    public function projectCreateTokenSuccess(): void
    {
        $body = $this->client->project()->tokens()->create(['name' => 'tok']);
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/project/tokens', $j->path);
        $this->assertSame('project.create_token', $j->matchedRoute);
    }

    #[Test]
    public function projectCreateTokenError(): void
    {
        $this->mock->scenarios()->set('project.create_token', 422, ['error' => 'bad']);
        try {
            $this->client->project()->tokens()->create(['name' => 'tok']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('project.create_token', $j->matchedRoute);
    }

    // ===== project.update_token (PATCH) ===============================

    #[Test]
    public function projectUpdateTokenSuccess(): void
    {
        $body = $this->client->project()->tokens()->update('tok-1', ['name' => 'renamed']);
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('PATCH', $j->method);
        $this->assertSame('/api/project/tokens/tok-1', $j->path);
        $this->assertSame('project.update_token', $j->matchedRoute);
    }

    #[Test]
    public function projectUpdateTokenError(): void
    {
        $this->mock->scenarios()->set('project.update_token', 404, ['error' => 'nope']);
        try {
            $this->client->project()->tokens()->update('missing', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('project.update_token', $j->matchedRoute);
    }

    // ===== project.delete_token =======================================

    #[Test]
    public function projectDeleteTokenSuccess(): void
    {
        $body = $this->client->project()->tokens()->delete('tok-1');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame('/api/project/tokens/tok-1', $j->path);
        $this->assertSame('project.delete_token', $j->matchedRoute);
    }

    #[Test]
    public function projectDeleteTokenError(): void
    {
        $this->mock->scenarios()->set('project.delete_token', 404, ['error' => 'nope']);
        try {
            $this->client->project()->tokens()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('project.delete_token', $j->matchedRoute);
    }
}
