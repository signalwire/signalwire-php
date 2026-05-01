<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_compat_phone_numbers.py.
 *
 * Covers ``CompatPhoneNumbers`` CRUD (list, get, update, delete), purchase /
 * import, available countries, and toll-free search.
 */
class CompatPhoneNumbersMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = new RestClient('test_proj', 'test_tok', $this->mock->url());
    }

    // ----- list -----------------------------------------------------------

    #[Test]
    public function listReturnsPaginatedList(): void
    {
        $result = $this->client->compat()->phoneNumbers()->list();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('incoming_phone_numbers', $result);
        $this->assertIsArray($result['incoming_phone_numbers']);
    }

    #[Test]
    public function listJournalRecordsGetToIncomingPhoneNumbers(): void
    {
        $this->client->compat()->phoneNumbers()->list();
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/IncomingPhoneNumbers',
            $j->path
        );
    }

    // ----- get ------------------------------------------------------------

    #[Test]
    public function getReturnsPhoneNumberResource(): void
    {
        $result = $this->client->compat()->phoneNumbers()->get('PN_TEST');
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('phone_number', $result) || array_key_exists('sid', $result)
        );
    }

    #[Test]
    public function getJournalRecordsGetWithSid(): void
    {
        $this->client->compat()->phoneNumbers()->get('PN_GET');
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/IncomingPhoneNumbers/PN_GET',
            $j->path
        );
    }

    // ----- update ---------------------------------------------------------

    #[Test]
    public function updateReturnsPhoneNumberResource(): void
    {
        $result = $this->client->compat()->phoneNumbers()->update(
            'PN_U',
            ['FriendlyName' => 'updated']
        );
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('phone_number', $result) || array_key_exists('sid', $result)
        );
    }

    #[Test]
    public function updateJournalRecordsPostWithFriendlyName(): void
    {
        $this->client->compat()->phoneNumbers()->update(
            'PN_UU',
            ['FriendlyName' => 'updated', 'VoiceUrl' => 'https://a.b/v']
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/IncomingPhoneNumbers/PN_UU',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('updated', $body['FriendlyName']);
        $this->assertSame('https://a.b/v', $body['VoiceUrl']);
    }

    // ----- delete ---------------------------------------------------------

    #[Test]
    public function deleteNoExceptionOnDelete(): void
    {
        $result = $this->client->compat()->phoneNumbers()->delete('PN_D');
        $this->assertIsArray($result);
    }

    #[Test]
    public function deleteJournalRecordsDeleteAtPhoneNumberPath(): void
    {
        $this->client->compat()->phoneNumbers()->delete('PN_DEL');
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/IncomingPhoneNumbers/PN_DEL',
            $j->path
        );
    }

    // ----- purchase -------------------------------------------------------

    #[Test]
    public function purchaseReturnsPurchasedNumber(): void
    {
        $result = $this->client->compat()->phoneNumbers()->purchase(
            ['PhoneNumber' => '+15555550100']
        );
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('phone_number', $result) || array_key_exists('sid', $result)
        );
    }

    #[Test]
    public function purchaseJournalRecordsPostWithPhoneNumber(): void
    {
        $this->client->compat()->phoneNumbers()->purchase(
            ['PhoneNumber' => '+15555550100', 'FriendlyName' => 'Main']
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/IncomingPhoneNumbers',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('+15555550100', $body['PhoneNumber']);
        $this->assertSame('Main', $body['FriendlyName']);
    }

    // ----- importNumber ---------------------------------------------------

    #[Test]
    public function importNumberReturnsImportedNumber(): void
    {
        $result = $this->client->compat()->phoneNumbers()->importNumber(
            ['PhoneNumber' => '+15555550111']
        );
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('phone_number', $result) || array_key_exists('sid', $result)
        );
    }

    #[Test]
    public function importNumberJournalRecordsPostToImportedPhoneNumbers(): void
    {
        $this->client->compat()->phoneNumbers()->importNumber(
            ['PhoneNumber' => '+15555550111', 'VoiceUrl' => 'https://a.b/v']
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/ImportedPhoneNumbers',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('+15555550111', $body['PhoneNumber']);
    }

    // ----- listAvailableCountries ----------------------------------------

    #[Test]
    public function listAvailableCountriesReturnsCountriesCollection(): void
    {
        $result = $this->client->compat()->phoneNumbers()->listAvailableCountries();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('countries', $result);
        $this->assertIsArray($result['countries']);
    }

    #[Test]
    public function listAvailableCountriesJournalRecordsGetToAvailablePhoneNumbers(): void
    {
        $this->client->compat()->phoneNumbers()->listAvailableCountries();
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/AvailablePhoneNumbers',
            $j->path
        );
    }

    // ----- searchTollFree ------------------------------------------------

    #[Test]
    public function searchTollFreeReturnsAvailableNumbers(): void
    {
        $result = $this->client->compat()->phoneNumbers()->searchTollFree(
            'US',
            ['AreaCode' => '800']
        );
        $this->assertIsArray($result);
        $this->assertArrayHasKey('available_phone_numbers', $result);
        $this->assertIsArray($result['available_phone_numbers']);
    }

    #[Test]
    public function searchTollFreeJournalRecordsGetWithCountryInPath(): void
    {
        $this->client->compat()->phoneNumbers()->searchTollFree(
            'US',
            ['AreaCode' => '888']
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/AvailablePhoneNumbers/US/TollFree',
            $j->path
        );
        // The AreaCode should be on the query string, not body.
        $this->assertArrayHasKey('AreaCode', $j->queryParams);
        $this->assertSame(['888'], $j->queryParams['AreaCode']);
    }
}
