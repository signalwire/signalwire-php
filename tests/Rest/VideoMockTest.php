<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_video_mock.py.
 *
 * Exercises the Video API: room sessions, room recordings, conference
 * tokens, conference streams, and the top-level streams resource.
 */
class VideoMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = new RestClient('test_proj', 'test_tok', $this->mock->url());
    }

    // ----- Rooms — streams sub-resource -------------------------------

    #[Test]
    public function roomsListStreamsReturnsDataCollection(): void
    {
        $body = $this->client->video()->rooms()->listStreams('room-1');
        $this->assertIsArray($body);
        // /api/video/rooms/{id}/streams returns a paginated list ('data').
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/rooms/room-1/streams', $j->path);
        $this->assertNotNull($j->matchedRoute, 'spec gap: rooms streams list');
    }

    #[Test]
    public function roomsCreateStreamPostsKwargsInBody(): void
    {
        $body = $this->client->video()->rooms()->createStream(
            'room-1',
            ['url' => 'rtmp://example.com/live']
        );
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/video/rooms/room-1/streams', $j->path);
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame('rtmp://example.com/live', $bm['url'] ?? null);
    }

    // ----- Room sessions ---------------------------------------------

    #[Test]
    public function roomSessionsListReturnsDataCollection(): void
    {
        $body = $this->client->video()->roomSessions()->list();
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/room_sessions', $j->path);
    }

    #[Test]
    public function roomSessionsGetReturnsSessionObject(): void
    {
        $body = $this->client->video()->roomSessions()->get('sess-abc');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/room_sessions/sess-abc', $j->path);
        $this->assertNotNull($j->matchedRoute);
    }

    #[Test]
    public function roomSessionsListEventsUsesEventsSubpath(): void
    {
        $body = $this->client->video()->roomSessions()->listEvents('sess-1');
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/room_sessions/sess-1/events', $j->path);
    }

    #[Test]
    public function roomSessionsListRecordingsUsesRecordingsSubpath(): void
    {
        $body = $this->client->video()->roomSessions()->listRecordings('sess-2');
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/room_sessions/sess-2/recordings', $j->path);
    }

    // ----- Room recordings -------------------------------------------

    #[Test]
    public function roomRecordingsListReturnsDataCollection(): void
    {
        $body = $this->client->video()->roomRecordings()->list();
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/room_recordings', $j->path);
    }

    #[Test]
    public function roomRecordingsGetReturnsSingleRecording(): void
    {
        $body = $this->client->video()->roomRecordings()->get('rec-xyz');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/room_recordings/rec-xyz', $j->path);
    }

    #[Test]
    public function roomRecordingsDeleteReturnsArrayFor204(): void
    {
        // 204/empty turns into [] in the SDK.
        $body = $this->client->video()->roomRecordings()->delete('rec-del');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame('/api/video/room_recordings/rec-del', $j->path);
        $this->assertNotNull($j->matchedRoute);
    }

    #[Test]
    public function roomRecordingsListEventsUsesEventsSubpath(): void
    {
        $body = $this->client->video()->roomRecordings()->listEvents('rec-1');
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/room_recordings/rec-1/events', $j->path);
    }

    // ----- Conferences — sub-collections (tokens, streams) ----------

    #[Test]
    public function conferencesListConferenceTokens(): void
    {
        $body = $this->client->video()->conferences()->listConferenceTokens('conf-1');
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/conferences/conf-1/conference_tokens', $j->path);
    }

    #[Test]
    public function conferencesListStreams(): void
    {
        $body = $this->client->video()->conferences()->listStreams('conf-2');
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/conferences/conf-2/streams', $j->path);
    }

    // ----- Conference Tokens (top-level resource) -------------------

    #[Test]
    public function conferenceTokensGetReturnsSingleToken(): void
    {
        $body = $this->client->video()->conferenceTokens()->get('tok-1');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/conference_tokens/tok-1', $j->path);
        $this->assertNotNull($j->matchedRoute);
    }

    #[Test]
    public function conferenceTokensResetPostsToResetSubpath(): void
    {
        $body = $this->client->video()->conferenceTokens()->reset('tok-2');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/video/conference_tokens/tok-2/reset', $j->path);
        // reset is a no-body POST — body should be null/empty.
        $this->assertTrue(
            $j->body === null || $j->body === '' || $j->body === [],
            'expected null/empty body on no-body POST, got: ' . var_export($j->body, true)
        );
    }

    // ----- Streams (top-level) --------------------------------------

    #[Test]
    public function streamsGetReturnsStreamResource(): void
    {
        $body = $this->client->video()->streams()->get('stream-1');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/streams/stream-1', $j->path);
    }

    #[Test]
    public function streamsUpdateUsesPutWithKwargs(): void
    {
        // VideoStreams.update sends PUT.
        $body = $this->client->video()->streams()->update(
            'stream-2',
            ['url' => 'rtmp://example.com/new']
        );
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('PUT', $j->method);
        $this->assertSame('/api/video/streams/stream-2', $j->path);
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame('rtmp://example.com/new', $bm['url'] ?? null);
    }

    #[Test]
    public function streamsDelete(): void
    {
        $body = $this->client->video()->streams()->delete('stream-3');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame('/api/video/streams/stream-3', $j->path);
        $this->assertNotNull($j->matchedRoute);
    }
}
