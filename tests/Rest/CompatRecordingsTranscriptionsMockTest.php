<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_compat_recordings_transcriptions.py.
 *
 * Both ``CompatRecordings`` and ``CompatTranscriptions`` expose the same
 * surface (list / get / delete) under the account-scoped LAML path.
 */
class CompatRecordingsTranscriptionsMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = new RestClient('test_proj', 'test_tok', $this->mock->url());
    }

    // ----- Recordings ----------------------------------------------------

    #[Test]
    public function recordingsListReturnsPaginatedRecordings(): void
    {
        $result = $this->client->compat()->recordings()->list();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('recordings', $result);
        $this->assertIsArray($result['recordings']);
    }

    #[Test]
    public function recordingsListJournalRecordsGet(): void
    {
        $this->client->compat()->recordings()->list();
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Recordings',
            $j->path
        );
    }

    #[Test]
    public function recordingsGetReturnsRecordingResource(): void
    {
        $result = $this->client->compat()->recordings()->get('RE_TEST');
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('sid', $result) || array_key_exists('call_sid', $result)
        );
    }

    #[Test]
    public function recordingsGetJournalRecordsGetWithSid(): void
    {
        $this->client->compat()->recordings()->get('RE_GET');
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Recordings/RE_GET',
            $j->path
        );
    }

    #[Test]
    public function recordingsDeleteNoExceptionOnDelete(): void
    {
        $result = $this->client->compat()->recordings()->delete('RE_D');
        $this->assertIsArray($result);
    }

    #[Test]
    public function recordingsDeleteJournalRecordsDelete(): void
    {
        $this->client->compat()->recordings()->delete('RE_DEL');
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Recordings/RE_DEL',
            $j->path
        );
    }

    // ----- Transcriptions ------------------------------------------------

    #[Test]
    public function transcriptionsListReturnsPaginatedTranscriptions(): void
    {
        $result = $this->client->compat()->transcriptions()->list();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('transcriptions', $result);
        $this->assertIsArray($result['transcriptions']);
    }

    #[Test]
    public function transcriptionsListJournalRecordsGet(): void
    {
        $this->client->compat()->transcriptions()->list();
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Transcriptions',
            $j->path
        );
    }

    #[Test]
    public function transcriptionsGetReturnsTranscriptionResource(): void
    {
        $result = $this->client->compat()->transcriptions()->get('TR_TEST');
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('sid', $result) || array_key_exists('duration', $result)
        );
    }

    #[Test]
    public function transcriptionsGetJournalRecordsGetWithSid(): void
    {
        $this->client->compat()->transcriptions()->get('TR_GET');
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Transcriptions/TR_GET',
            $j->path
        );
    }

    #[Test]
    public function transcriptionsDeleteNoExceptionOnDelete(): void
    {
        $result = $this->client->compat()->transcriptions()->delete('TR_D');
        $this->assertIsArray($result);
    }

    #[Test]
    public function transcriptionsDeleteJournalRecordsDelete(): void
    {
        $this->client->compat()->transcriptions()->delete('TR_DEL');
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Transcriptions/TR_DEL',
            $j->path
        );
    }
}
