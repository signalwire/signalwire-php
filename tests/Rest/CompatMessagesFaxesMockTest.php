<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_compat_messages_faxes.py.
 *
 * Covers ``CompatMessages`` (update + media) and ``CompatFaxes`` (update +
 * media) gap entries.
 */
class CompatMessagesFaxesMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = new RestClient('test_proj', 'test_tok', $this->mock->url());
    }

    // ----- Messages -------------------------------------------------------

    #[Test]
    public function messagesUpdateReturnsMessageResource(): void
    {
        $result = $this->client->compat()->messages()->update(
            'MM_TEST',
            ['Body' => 'updated body']
        );
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('body', $result) || array_key_exists('sid', $result)
        );
    }

    #[Test]
    public function messagesUpdateJournalRecordsPostToMessage(): void
    {
        $this->client->compat()->messages()->update(
            'MM_U1',
            ['Body' => 'x', 'Status' => 'canceled']
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Messages/MM_U1',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('x', $body['Body']);
        $this->assertSame('canceled', $body['Status']);
    }

    #[Test]
    public function messagesGetMediaReturnsMediaResource(): void
    {
        $result = $this->client->compat()->messages()->getMedia('MM_GM', 'ME_GM');
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('content_type', $result) || array_key_exists('sid', $result)
        );
    }

    #[Test]
    public function messagesGetMediaJournalRecordsGetToMediaPath(): void
    {
        $this->client->compat()->messages()->getMedia('MM_X', 'ME_X');
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Messages/MM_X/Media/ME_X',
            $j->path
        );
    }

    #[Test]
    public function messagesDeleteMediaNoExceptionOnDelete(): void
    {
        $result = $this->client->compat()->messages()->deleteMedia('MM_DM', 'ME_DM');
        // The SDK's DELETE handler returns [] on 204 or whatever the mock
        // body is for non-204 responses. Either way we expect an array.
        $this->assertIsArray($result);
    }

    #[Test]
    public function messagesDeleteMediaJournalRecordsDelete(): void
    {
        $this->client->compat()->messages()->deleteMedia('MM_D', 'ME_D');
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Messages/MM_D/Media/ME_D',
            $j->path
        );
    }

    // ----- Faxes ----------------------------------------------------------

    #[Test]
    public function faxesUpdateReturnsFaxResource(): void
    {
        $result = $this->client->compat()->faxes()->update('FX_U', ['Status' => 'canceled']);
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('status', $result) || array_key_exists('direction', $result)
        );
    }

    #[Test]
    public function faxesUpdateJournalRecordsPostWithStatus(): void
    {
        $this->client->compat()->faxes()->update('FX_U2', ['Status' => 'canceled']);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Faxes/FX_U2',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('canceled', $body['Status']);
    }

    #[Test]
    public function faxesListMediaReturnsPaginatedList(): void
    {
        $result = $this->client->compat()->faxes()->listMedia('FX_LM');
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('media', $result) || array_key_exists('fax_media', $result),
            "expected 'media' or 'fax_media' key, got " . implode(',', array_keys($result))
        );
    }

    #[Test]
    public function faxesListMediaJournalRecordsGetToFaxMedia(): void
    {
        $this->client->compat()->faxes()->listMedia('FX_LM_X');
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Faxes/FX_LM_X/Media',
            $j->path
        );
    }

    #[Test]
    public function faxesGetMediaReturnsFaxMediaResource(): void
    {
        $result = $this->client->compat()->faxes()->getMedia('FX_GM', 'ME_GM');
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('content_type', $result) || array_key_exists('sid', $result)
        );
    }

    #[Test]
    public function faxesGetMediaJournalRecordsGetToSpecificMedia(): void
    {
        $this->client->compat()->faxes()->getMedia('FX_G', 'ME_G');
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Faxes/FX_G/Media/ME_G',
            $j->path
        );
    }

    #[Test]
    public function faxesDeleteMediaNoExceptionOnDelete(): void
    {
        $result = $this->client->compat()->faxes()->deleteMedia('FX_DM', 'ME_DM');
        $this->assertIsArray($result);
    }

    #[Test]
    public function faxesDeleteMediaJournalRecordsDelete(): void
    {
        $this->client->compat()->faxes()->deleteMedia('FX_D', 'ME_D');
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Faxes/FX_D/Media/ME_D',
            $j->path
        );
    }
}
