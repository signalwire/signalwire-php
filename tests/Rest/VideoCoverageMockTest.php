<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\REST\RestClient;
use SignalWire\REST\SignalWireRestError;

/**
 * Full success+error coverage matrix for the video.* route group.
 *
 * Complements the hand-written tests/Rest/VideoMockTest.php with both a
 * success (2xx) and an error (4xx/5xx) test for every coverable canonical
 * route. Accepted gaps (allowlisted, no SDK method by design):
 *   - video.list_logs, video.get_log  (no video logs accessor)
 *   - video.get_room  (routing collision; partner get_room_by_name is covered)
 */
class VideoCoverageMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;
    private string $project;

    protected function setUp(): void
    {
        [$this->client, $this->mock, $this->project] = MockTest::scopedClient();
    }

    // ===== rooms ======================================================

    #[Test]
    public function listRoomsSuccess(): void
    {
        $body = $this->client->video()->rooms()->list();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/rooms', $j->path);
        $this->assertSame('video.list_rooms', $j->matchedRoute);
    }

    #[Test]
    public function listRoomsError(): void
    {
        $this->mock->scenarios()->set('video.list_rooms', 500, ['error' => 'boom']);
        try {
            $this->client->video()->rooms()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('video.list_rooms', $j->matchedRoute);
    }

    #[Test]
    public function createRoomSuccess(): void
    {
        $body = $this->client->video()->rooms()->create(['name' => 'room-alpha']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/video/rooms', $j->path);
        $this->assertSame('video.create_room', $j->matchedRoute);
    }

    #[Test]
    public function createRoomError(): void
    {
        $this->mock->scenarios()->set('video.create_room', 422, ['error' => 'invalid']);
        try {
            $this->client->video()->rooms()->create([]);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('video.create_room', $j->matchedRoute);
    }

    #[Test]
    public function getRoomByNameSuccess(): void
    {
        // rooms.get(id) hits GET /rooms/{id}; the router resolves the longer
        // {name} template, so this journals as video.get_room_by_name.
        $body = $this->client->video()->rooms()->get('room-1001');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/rooms/room-1001', $j->path);
        $this->assertSame('video.get_room_by_name', $j->matchedRoute);
    }

    #[Test]
    public function getRoomByNameError(): void
    {
        $this->mock->scenarios()->set('video.get_room_by_name', 404, ['error' => 'not found']);
        try {
            $this->client->video()->rooms()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('video.get_room_by_name', $j->matchedRoute);
    }

    #[Test]
    public function updateRoomSuccess(): void
    {
        $body = $this->client->video()->rooms()->update('room-1001', ['display_name' => 'renamed']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('PUT', $j->method);
        $this->assertSame('/api/video/rooms/room-1001', $j->path);
        $this->assertSame('video.update_room', $j->matchedRoute);
    }

    #[Test]
    public function updateRoomError(): void
    {
        $this->mock->scenarios()->set('video.update_room', 404, ['error' => 'not found']);
        try {
            $this->client->video()->rooms()->update('missing', ['display_name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('video.update_room', $j->matchedRoute);
    }

    #[Test]
    public function deleteRoomSuccess(): void
    {
        $body = $this->client->video()->rooms()->delete('room-1001');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame('/api/video/rooms/room-1001', $j->path);
        $this->assertSame('video.delete_room', $j->matchedRoute);
    }

    #[Test]
    public function deleteRoomError(): void
    {
        $this->mock->scenarios()->set('video.delete_room', 404, ['error' => 'not found']);
        try {
            $this->client->video()->rooms()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('video.delete_room', $j->matchedRoute);
    }

    #[Test]
    public function listRoomStreamsSuccess(): void
    {
        $body = $this->client->video()->rooms()->listStreams('room-1001');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/rooms/room-1001/streams', $j->path);
        $this->assertSame('video.list_room_streams', $j->matchedRoute);
    }

    #[Test]
    public function listRoomStreamsError(): void
    {
        $this->mock->scenarios()->set('video.list_room_streams', 500, ['error' => 'boom']);
        try {
            $this->client->video()->rooms()->listStreams('room-1001');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('video.list_room_streams', $j->matchedRoute);
    }

    #[Test]
    public function createRoomStreamSuccess(): void
    {
        $body = $this->client->video()->rooms()->createStream(
            'room-1001',
            'rtmp://example.com/live'
        );
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/video/rooms/room-1001/streams', $j->path);
        $this->assertSame('video.create_room_stream', $j->matchedRoute);
    }

    #[Test]
    public function createRoomStreamError(): void
    {
        $this->mock->scenarios()->set('video.create_room_stream', 422, ['error' => 'invalid']);
        try {
            $this->client->video()->rooms()->createStream('room-1001', 'rtmp://example.com/live');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('video.create_room_stream', $j->matchedRoute);
    }

    // ===== room tokens ================================================

    #[Test]
    public function createRoomTokenSuccess(): void
    {
        $body = $this->client->video()->roomTokens()->create('room-alpha');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/video/room_tokens', $j->path);
        $this->assertSame('video.create_room_token', $j->matchedRoute);
    }

    #[Test]
    public function createRoomTokenError(): void
    {
        $this->mock->scenarios()->set('video.create_room_token', 422, ['error' => 'invalid']);
        try {
            $this->client->video()->roomTokens()->create('room-alpha');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('video.create_room_token', $j->matchedRoute);
    }

    // ===== room sessions ==============================================

    #[Test]
    public function listRoomSessionsSuccess(): void
    {
        $body = $this->client->video()->roomSessions()->list();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/room_sessions', $j->path);
        $this->assertSame('video.list_room_sessions', $j->matchedRoute);
    }

    #[Test]
    public function listRoomSessionsError(): void
    {
        $this->mock->scenarios()->set('video.list_room_sessions', 500, ['error' => 'boom']);
        try {
            $this->client->video()->roomSessions()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('video.list_room_sessions', $j->matchedRoute);
    }

    #[Test]
    public function getRoomSessionSuccess(): void
    {
        $body = $this->client->video()->roomSessions()->get('sess-1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/room_sessions/sess-1', $j->path);
        $this->assertSame('video.get_room_session', $j->matchedRoute);
    }

    #[Test]
    public function getRoomSessionError(): void
    {
        $this->mock->scenarios()->set('video.get_room_session', 404, ['error' => 'not found']);
        try {
            $this->client->video()->roomSessions()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('video.get_room_session', $j->matchedRoute);
    }

    #[Test]
    public function listRoomSessionEventsSuccess(): void
    {
        $body = $this->client->video()->roomSessions()->listEvents('sess-1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/room_sessions/sess-1/events', $j->path);
        $this->assertSame('video.list_room_session_events', $j->matchedRoute);
    }

    #[Test]
    public function listRoomSessionEventsError(): void
    {
        $this->mock->scenarios()->set('video.list_room_session_events', 500, ['error' => 'boom']);
        try {
            $this->client->video()->roomSessions()->listEvents('sess-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('video.list_room_session_events', $j->matchedRoute);
    }

    #[Test]
    public function listRoomSessionMembersSuccess(): void
    {
        $body = $this->client->video()->roomSessions()->listMembers('sess-1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/room_sessions/sess-1/members', $j->path);
        $this->assertSame('video.list_room_session_members', $j->matchedRoute);
    }

    #[Test]
    public function listRoomSessionMembersError(): void
    {
        $this->mock->scenarios()->set('video.list_room_session_members', 500, ['error' => 'boom']);
        try {
            $this->client->video()->roomSessions()->listMembers('sess-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('video.list_room_session_members', $j->matchedRoute);
    }

    #[Test]
    public function listRoomSessionRecordingsSuccess(): void
    {
        $body = $this->client->video()->roomSessions()->listRecordings('sess-1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/room_sessions/sess-1/recordings', $j->path);
        $this->assertSame('video.list_room_session_recordings', $j->matchedRoute);
    }

    #[Test]
    public function listRoomSessionRecordingsError(): void
    {
        $this->mock->scenarios()->set('video.list_room_session_recordings', 500, ['error' => 'boom']);
        try {
            $this->client->video()->roomSessions()->listRecordings('sess-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('video.list_room_session_recordings', $j->matchedRoute);
    }

    // ===== room recordings ============================================

    #[Test]
    public function listRoomRecordingsSuccess(): void
    {
        $body = $this->client->video()->roomRecordings()->list();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/room_recordings', $j->path);
        $this->assertSame('video.list_room_recordings', $j->matchedRoute);
    }

    #[Test]
    public function listRoomRecordingsError(): void
    {
        $this->mock->scenarios()->set('video.list_room_recordings', 500, ['error' => 'boom']);
        try {
            $this->client->video()->roomRecordings()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('video.list_room_recordings', $j->matchedRoute);
    }

    #[Test]
    public function getRoomRecordingSuccess(): void
    {
        $body = $this->client->video()->roomRecordings()->get('rec-1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/room_recordings/rec-1', $j->path);
        $this->assertSame('video.get_room_recording', $j->matchedRoute);
    }

    #[Test]
    public function getRoomRecordingError(): void
    {
        $this->mock->scenarios()->set('video.get_room_recording', 404, ['error' => 'not found']);
        try {
            $this->client->video()->roomRecordings()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('video.get_room_recording', $j->matchedRoute);
    }

    #[Test]
    public function deleteRoomRecordingSuccess(): void
    {
        $body = $this->client->video()->roomRecordings()->delete('rec-1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame('/api/video/room_recordings/rec-1', $j->path);
        $this->assertSame('video.delete_room_recording', $j->matchedRoute);
    }

    #[Test]
    public function deleteRoomRecordingError(): void
    {
        $this->mock->scenarios()->set('video.delete_room_recording', 404, ['error' => 'not found']);
        try {
            $this->client->video()->roomRecordings()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('video.delete_room_recording', $j->matchedRoute);
    }

    #[Test]
    public function listRoomRecordingEventsSuccess(): void
    {
        $body = $this->client->video()->roomRecordings()->listEvents('rec-1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/room_recordings/rec-1/events', $j->path);
        $this->assertSame('video.list_room_recording_events', $j->matchedRoute);
    }

    #[Test]
    public function listRoomRecordingEventsError(): void
    {
        $this->mock->scenarios()->set('video.list_room_recording_events', 500, ['error' => 'boom']);
        try {
            $this->client->video()->roomRecordings()->listEvents('rec-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('video.list_room_recording_events', $j->matchedRoute);
    }

    // ===== conferences ================================================

    #[Test]
    public function listVideoConferencesSuccess(): void
    {
        $body = $this->client->video()->conferences()->list();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/conferences', $j->path);
        $this->assertSame('video.list_video_conferences', $j->matchedRoute);
    }

    #[Test]
    public function listVideoConferencesError(): void
    {
        $this->mock->scenarios()->set('video.list_video_conferences', 500, ['error' => 'boom']);
        try {
            $this->client->video()->conferences()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('video.list_video_conferences', $j->matchedRoute);
    }

    #[Test]
    public function createVideoConferenceSuccess(): void
    {
        $body = $this->client->video()->conferences()->create(['name' => 'conf-alpha']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/video/conferences', $j->path);
        $this->assertSame('video.create_video_conference', $j->matchedRoute);
    }

    #[Test]
    public function createVideoConferenceError(): void
    {
        $this->mock->scenarios()->set('video.create_video_conference', 422, ['error' => 'invalid']);
        try {
            $this->client->video()->conferences()->create([]);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('video.create_video_conference', $j->matchedRoute);
    }

    #[Test]
    public function getVideoConferenceSuccess(): void
    {
        $body = $this->client->video()->conferences()->get('conf-1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/conferences/conf-1', $j->path);
        $this->assertSame('video.get_video_conference', $j->matchedRoute);
    }

    #[Test]
    public function getVideoConferenceError(): void
    {
        $this->mock->scenarios()->set('video.get_video_conference', 404, ['error' => 'not found']);
        try {
            $this->client->video()->conferences()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('video.get_video_conference', $j->matchedRoute);
    }

    #[Test]
    public function updateVideoConferenceSuccess(): void
    {
        $body = $this->client->video()->conferences()->update('conf-1', ['name' => 'renamed']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('PUT', $j->method);
        $this->assertSame('/api/video/conferences/conf-1', $j->path);
        $this->assertSame('video.update_video_conference', $j->matchedRoute);
    }

    #[Test]
    public function updateVideoConferenceError(): void
    {
        $this->mock->scenarios()->set('video.update_video_conference', 404, ['error' => 'not found']);
        try {
            $this->client->video()->conferences()->update('missing', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('video.update_video_conference', $j->matchedRoute);
    }

    #[Test]
    public function deleteVideoConferenceSuccess(): void
    {
        $body = $this->client->video()->conferences()->delete('conf-1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame('/api/video/conferences/conf-1', $j->path);
        $this->assertSame('video.delete_video_conference', $j->matchedRoute);
    }

    #[Test]
    public function deleteVideoConferenceError(): void
    {
        $this->mock->scenarios()->set('video.delete_video_conference', 404, ['error' => 'not found']);
        try {
            $this->client->video()->conferences()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('video.delete_video_conference', $j->matchedRoute);
    }

    #[Test]
    public function listConferenceTokensSuccess(): void
    {
        $body = $this->client->video()->conferences()->listConferenceTokens('conf-1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/conferences/conf-1/conference_tokens', $j->path);
        $this->assertSame('video.list_conference_tokens', $j->matchedRoute);
    }

    #[Test]
    public function listConferenceTokensError(): void
    {
        $this->mock->scenarios()->set('video.list_conference_tokens', 500, ['error' => 'boom']);
        try {
            $this->client->video()->conferences()->listConferenceTokens('conf-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('video.list_conference_tokens', $j->matchedRoute);
    }

    #[Test]
    public function listConferenceStreamsSuccess(): void
    {
        $body = $this->client->video()->conferences()->listStreams('conf-1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/conferences/conf-1/streams', $j->path);
        $this->assertSame('video.list_conference_streams', $j->matchedRoute);
    }

    #[Test]
    public function listConferenceStreamsError(): void
    {
        $this->mock->scenarios()->set('video.list_conference_streams', 500, ['error' => 'boom']);
        try {
            $this->client->video()->conferences()->listStreams('conf-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('video.list_conference_streams', $j->matchedRoute);
    }

    #[Test]
    public function createConferenceStreamSuccess(): void
    {
        $body = $this->client->video()->conferences()->createStream(
            'conf-1',
            'rtmp://example.com/live'
        );
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/video/conferences/conf-1/streams', $j->path);
        $this->assertSame('video.create_conference_stream', $j->matchedRoute);
    }

    #[Test]
    public function createConferenceStreamError(): void
    {
        $this->mock->scenarios()->set('video.create_conference_stream', 422, ['error' => 'invalid']);
        try {
            $this->client->video()->conferences()->createStream('conf-1', 'rtmp://example.com/live');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('video.create_conference_stream', $j->matchedRoute);
    }

    // ===== conference tokens ==========================================

    #[Test]
    public function getConferenceTokenSuccess(): void
    {
        $body = $this->client->video()->conferenceTokens()->get('tok-1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/conference_tokens/tok-1', $j->path);
        $this->assertSame('video.get_conference_token', $j->matchedRoute);
    }

    #[Test]
    public function getConferenceTokenError(): void
    {
        $this->mock->scenarios()->set('video.get_conference_token', 404, ['error' => 'not found']);
        try {
            $this->client->video()->conferenceTokens()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('video.get_conference_token', $j->matchedRoute);
    }

    #[Test]
    public function resetConferenceTokenSuccess(): void
    {
        $body = $this->client->video()->conferenceTokens()->reset('tok-1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/video/conference_tokens/tok-1/reset', $j->path);
        $this->assertSame('video.reset_conference_token', $j->matchedRoute);
    }

    #[Test]
    public function resetConferenceTokenError(): void
    {
        $this->mock->scenarios()->set('video.reset_conference_token', 404, ['error' => 'not found']);
        try {
            $this->client->video()->conferenceTokens()->reset('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('video.reset_conference_token', $j->matchedRoute);
    }

    // ===== streams (top-level) ========================================

    #[Test]
    public function getStreamSuccess(): void
    {
        $body = $this->client->video()->streams()->get('stream-1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/video/streams/stream-1', $j->path);
        $this->assertSame('video.get_stream', $j->matchedRoute);
    }

    #[Test]
    public function getStreamError(): void
    {
        $this->mock->scenarios()->set('video.get_stream', 404, ['error' => 'not found']);
        try {
            $this->client->video()->streams()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('video.get_stream', $j->matchedRoute);
    }

    #[Test]
    public function updateStreamSuccess(): void
    {
        $body = $this->client->video()->streams()->update('stream-1', 'rtmp://example.com/new');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('PUT', $j->method);
        $this->assertSame('/api/video/streams/stream-1', $j->path);
        $this->assertSame('video.update_stream', $j->matchedRoute);
    }

    #[Test]
    public function updateStreamError(): void
    {
        $this->mock->scenarios()->set('video.update_stream', 404, ['error' => 'not found']);
        try {
            $this->client->video()->streams()->update('missing', 'x');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('video.update_stream', $j->matchedRoute);
    }

    #[Test]
    public function deleteStreamSuccess(): void
    {
        $body = $this->client->video()->streams()->delete('stream-1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame('/api/video/streams/stream-1', $j->path);
        $this->assertSame('video.delete_stream', $j->matchedRoute);
    }

    #[Test]
    public function deleteStreamError(): void
    {
        $this->mock->scenarios()->set('video.delete_stream', 404, ['error' => 'not found']);
        try {
            $this->client->video()->streams()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('video.delete_stream', $j->matchedRoute);
    }
}
