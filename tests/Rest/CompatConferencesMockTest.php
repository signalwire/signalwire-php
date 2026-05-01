<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_compat_conferences.py.
 *
 * Covers all 12 Conference symbols: list/get/update on the conference
 * itself, plus the participant, recording, and stream sub-resources.
 */
class CompatConferencesMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = new RestClient('test_proj', 'test_tok', $this->mock->url());
    }

    // ----- conference itself ------------------------------------------

    #[Test]
    public function listReturnsPaginatedList(): void
    {
        $result = $this->client->compat()->conferences()->list();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('conferences', $result);
        $this->assertIsArray($result['conferences']);
        $this->assertArrayHasKey('page', $result);
        $this->assertIsInt($result['page']);
    }

    #[Test]
    public function listJournalRecordsGetToConferences(): void
    {
        $this->client->compat()->conferences()->list();
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Conferences',
            $j->path
        );
        $this->assertNotNull($j->matchedRoute, 'spec gap: conferences.list');
    }

    #[Test]
    public function getReturnsConferenceResource(): void
    {
        $result = $this->client->compat()->conferences()->get('CF_TEST');
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('friendly_name', $result) || array_key_exists('status', $result)
        );
    }

    #[Test]
    public function getJournalRecordsGetWithSid(): void
    {
        $this->client->compat()->conferences()->get('CF_GETSID');
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Conferences/CF_GETSID',
            $j->path
        );
    }

    #[Test]
    public function updateReturnsUpdatedConference(): void
    {
        $result = $this->client->compat()->conferences()->update(
            'CF_X',
            ['Status' => 'completed']
        );
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('friendly_name', $result) || array_key_exists('status', $result)
        );
    }

    #[Test]
    public function updateJournalRecordsPostWithStatus(): void
    {
        $this->client->compat()->conferences()->update(
            'CF_UPD',
            ['Status' => 'completed', 'AnnounceUrl' => 'https://a.b']
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Conferences/CF_UPD',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('completed', $body['Status'] ?? null);
        $this->assertSame('https://a.b', $body['AnnounceUrl'] ?? null);
    }

    // ----- participants -----------------------------------------------

    #[Test]
    public function getParticipantReturnsParticipant(): void
    {
        $result = $this->client->compat()->conferences()->getParticipant('CF_P', 'CA_P');
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('call_sid', $result) || array_key_exists('conference_sid', $result)
        );
    }

    #[Test]
    public function getParticipantJournalRecordsGetToParticipant(): void
    {
        $this->client->compat()->conferences()->getParticipant('CF_GP', 'CA_GP');
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Conferences/CF_GP/Participants/CA_GP',
            $j->path
        );
    }

    #[Test]
    public function updateParticipantReturnsParticipantResource(): void
    {
        $result = $this->client->compat()->conferences()->updateParticipant(
            'CF_UP',
            'CA_UP',
            ['Muted' => true]
        );
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('call_sid', $result) || array_key_exists('conference_sid', $result)
        );
    }

    #[Test]
    public function updateParticipantJournalRecordsPostWithMuteFlag(): void
    {
        $this->client->compat()->conferences()->updateParticipant(
            'CF_M',
            'CA_M',
            ['Muted' => true, 'Hold' => false]
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Conferences/CF_M/Participants/CA_M',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertTrue($body['Muted'] ?? null);
        $this->assertFalse($body['Hold'] ?? null);
    }

    #[Test]
    public function removeParticipantReturnsArray(): void
    {
        // 204-style deletes return [] from the SDK; a synthesized response may
        // also return a body. Either is acceptable — what matters is no exception.
        $result = $this->client->compat()->conferences()->removeParticipant('CF_R', 'CA_R');
        $this->assertIsArray($result);
    }

    #[Test]
    public function removeParticipantJournalRecordsDelete(): void
    {
        $this->client->compat()->conferences()->removeParticipant('CF_RM', 'CA_RM');
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Conferences/CF_RM/Participants/CA_RM',
            $j->path
        );
    }

    // ----- recordings -------------------------------------------------

    #[Test]
    public function listRecordingsReturnsPaginatedRecordings(): void
    {
        $result = $this->client->compat()->conferences()->listRecordings('CF_LR');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('recordings', $result);
        $this->assertIsArray($result['recordings']);
    }

    #[Test]
    public function listRecordingsJournalRecordsGet(): void
    {
        $this->client->compat()->conferences()->listRecordings('CF_LRX');
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Conferences/CF_LRX/Recordings',
            $j->path
        );
    }

    #[Test]
    public function getRecordingReturnsRecordingResource(): void
    {
        $result = $this->client->compat()->conferences()->getRecording('CF_GR', 'RE_GR');
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('sid', $result) || array_key_exists('call_sid', $result)
        );
    }

    #[Test]
    public function getRecordingJournalRecordsGet(): void
    {
        $this->client->compat()->conferences()->getRecording('CF_GRX', 'RE_GRX');
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Conferences/CF_GRX/Recordings/RE_GRX',
            $j->path
        );
    }

    #[Test]
    public function updateRecordingReturnsRecordingResource(): void
    {
        $result = $this->client->compat()->conferences()->updateRecording(
            'CF_URC',
            'RE_URC',
            ['Status' => 'paused']
        );
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('sid', $result) || array_key_exists('status', $result)
        );
    }

    #[Test]
    public function updateRecordingJournalRecordsPostWithStatus(): void
    {
        $this->client->compat()->conferences()->updateRecording(
            'CF_UR',
            'RE_UR',
            ['Status' => 'paused']
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Conferences/CF_UR/Recordings/RE_UR',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('paused', $body['Status'] ?? null);
    }

    #[Test]
    public function deleteRecordingReturnsArray(): void
    {
        $result = $this->client->compat()->conferences()->deleteRecording('CF_DR', 'RE_DR');
        $this->assertIsArray($result);
    }

    #[Test]
    public function deleteRecordingJournalRecordsDelete(): void
    {
        $this->client->compat()->conferences()->deleteRecording('CF_DRX', 'RE_DRX');
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Conferences/CF_DRX/Recordings/RE_DRX',
            $j->path
        );
    }

    // ----- streams ----------------------------------------------------

    #[Test]
    public function startStreamReturnsStreamResource(): void
    {
        $result = $this->client->compat()->conferences()->startStream(
            'CF_SS',
            ['Url' => 'wss://a.b/s']
        );
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('sid', $result) || array_key_exists('name', $result)
        );
    }

    #[Test]
    public function startStreamJournalRecordsPostToStreams(): void
    {
        $this->client->compat()->conferences()->startStream(
            'CF_SSX',
            ['Url' => 'wss://a.b/s', 'Name' => 'strm']
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Conferences/CF_SSX/Streams',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('wss://a.b/s', $body['Url'] ?? null);
    }

    #[Test]
    public function stopStreamReturnsStreamResource(): void
    {
        $result = $this->client->compat()->conferences()->stopStream(
            'CF_TS',
            'ST_TS',
            ['Status' => 'stopped']
        );
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('sid', $result) || array_key_exists('status', $result)
        );
    }

    #[Test]
    public function stopStreamJournalRecordsPostToSpecificStream(): void
    {
        $this->client->compat()->conferences()->stopStream(
            'CF_TSX',
            'ST_TSX',
            ['Status' => 'stopped']
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Conferences/CF_TSX/Streams/ST_TSX',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('stopped', $body['Status'] ?? null);
    }
}
