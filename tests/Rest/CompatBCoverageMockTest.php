<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\REST\RestClient;
use SignalWire\REST\SignalWireRestError;

/**
 * Full success + error REST coverage for the COMPAT-B group: the LAML
 * (Twilio-compatible) Messages, Media, Faxes, Conferences, Participants,
 * Queues, Queue Members, IncomingPhoneNumbers and AvailablePhoneNumbers
 * routes.
 *
 * Each canonical route gets a SUCCESS test (real SDK call, body-shape +
 * journal method/path/matchedRoute assertions) and an ERROR test (a scenario
 * arms a 4xx/5xx; the SDK raises SignalWireRestError with matching status).
 *
 * SKIPPED GAP (allowlisted, no SDK method by design):
 *   compatibility.list_available_phone_number_resources_by_country
 */
class CompatBCoverageMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;
    private string $project;

    protected function setUp(): void
    {
        [$this->client, $this->mock, $this->project] = MockTest::scopedClient();
    }

    private function base(): string
    {
        return '/api/laml/2010-04-01/Accounts/' . $this->project;
    }

    // ==================================================================
    // Messages
    // ==================================================================

    #[Test]
    public function listMessagesSuccess(): void
    {
        $body = $this->client->compat()->messages()->list();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Messages', $j->path);
        $this->assertSame('compatibility.list_messages', $j->matchedRoute);
    }

    #[Test]
    public function listMessagesError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_messages', 500, ['error' => 'internal']);
        try {
            $this->client->compat()->messages()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('compatibility.list_messages', $j->matchedRoute);
    }

    #[Test]
    public function createMessageSuccess(): void
    {
        $body = $this->client->compat()->messages()->create(
            ['To' => '+15551112222', 'From' => '+15553334444', 'Body' => 'hi']
        );
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base() . '/Messages', $j->path);
        $this->assertSame('compatibility.create_message', $j->matchedRoute);
    }

    #[Test]
    public function createMessageError(): void
    {
        $this->mock->scenarios()->set('compatibility.create_message', 422, ['error' => 'bad']);
        try {
            $this->client->compat()->messages()->create(['To' => '+1', 'From' => '+1', 'Body' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('compatibility.create_message', $j->matchedRoute);
    }

    #[Test]
    public function listMediaSuccess(): void
    {
        $body = $this->client->compat()->messages()->listMedia('MM1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Messages/MM1/Media', $j->path);
        $this->assertSame('compatibility.list_media', $j->matchedRoute);
    }

    #[Test]
    public function listMediaError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_media', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->messages()->listMedia('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.list_media', $j->matchedRoute);
    }

    #[Test]
    public function retrieveMediaSuccess(): void
    {
        $body = $this->client->compat()->messages()->getMedia('MM1', 'ME1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Messages/MM1/Media/ME1', $j->path);
        $this->assertSame('compatibility.retrieve_media', $j->matchedRoute);
    }

    #[Test]
    public function retrieveMediaError(): void
    {
        $this->mock->scenarios()->set('compatibility.retrieve_media', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->messages()->getMedia('MM1', 'missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.retrieve_media', $j->matchedRoute);
    }

    #[Test]
    public function deleteMessageMediaSuccess(): void
    {
        $body = $this->client->compat()->messages()->deleteMedia('MM1', 'ME1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame($this->base() . '/Messages/MM1/Media/ME1', $j->path);
        $this->assertSame('compatibility.delete_message_media', $j->matchedRoute);
    }

    #[Test]
    public function deleteMessageMediaError(): void
    {
        $this->mock->scenarios()->set('compatibility.delete_message_media', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->messages()->deleteMedia('MM1', 'missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.delete_message_media', $j->matchedRoute);
    }

    #[Test]
    public function retrieveMessageSuccess(): void
    {
        $body = $this->client->compat()->messages()->get('MM1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Messages/MM1', $j->path);
        $this->assertSame('compatibility.retrieve_message', $j->matchedRoute);
    }

    #[Test]
    public function retrieveMessageError(): void
    {
        $this->mock->scenarios()->set('compatibility.retrieve_message', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->messages()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.retrieve_message', $j->matchedRoute);
    }

    #[Test]
    public function updateMessageSuccess(): void
    {
        $body = $this->client->compat()->messages()->update('MM1', ['Body' => 'redacted']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base() . '/Messages/MM1', $j->path);
        $this->assertSame('compatibility.update_message', $j->matchedRoute);
    }

    #[Test]
    public function updateMessageError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_message', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->messages()->update('missing', ['Body' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_message', $j->matchedRoute);
    }

    #[Test]
    public function deleteMessageSuccess(): void
    {
        $body = $this->client->compat()->messages()->delete('MM1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame($this->base() . '/Messages/MM1', $j->path);
        $this->assertSame('compatibility.delete_message', $j->matchedRoute);
    }

    #[Test]
    public function deleteMessageError(): void
    {
        $this->mock->scenarios()->set('compatibility.delete_message', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->messages()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.delete_message', $j->matchedRoute);
    }

    // ==================================================================
    // Faxes
    // ==================================================================

    #[Test]
    public function listAllFaxesSuccess(): void
    {
        $body = $this->client->compat()->faxes()->list();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Faxes', $j->path);
        $this->assertSame('compatibility.list_all_faxes', $j->matchedRoute);
    }

    #[Test]
    public function listAllFaxesError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_all_faxes', 500, ['error' => 'internal']);
        try {
            $this->client->compat()->faxes()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('compatibility.list_all_faxes', $j->matchedRoute);
    }

    #[Test]
    public function sendFaxSuccess(): void
    {
        $body = $this->client->compat()->faxes()->create(
            ['To' => '+15551112222', 'From' => '+15553334444', 'MediaUrl' => 'https://x/y.pdf']
        );
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base() . '/Faxes', $j->path);
        $this->assertSame('compatibility.send_fax', $j->matchedRoute);
    }

    #[Test]
    public function sendFaxError(): void
    {
        $this->mock->scenarios()->set('compatibility.send_fax', 422, ['error' => 'bad']);
        try {
            $this->client->compat()->faxes()->create(['To' => '+1', 'From' => '+1', 'MediaUrl' => 'u']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('compatibility.send_fax', $j->matchedRoute);
    }

    #[Test]
    public function listAllFaxMediaSuccess(): void
    {
        $body = $this->client->compat()->faxes()->listMedia('FX1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Faxes/FX1/Media', $j->path);
        $this->assertSame('compatibility.list_all_fax_media', $j->matchedRoute);
    }

    #[Test]
    public function listAllFaxMediaError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_all_fax_media', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->faxes()->listMedia('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.list_all_fax_media', $j->matchedRoute);
    }

    #[Test]
    public function retrieveMediasSuccess(): void
    {
        $body = $this->client->compat()->faxes()->getMedia('FX1', 'ME1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Faxes/FX1/Media/ME1', $j->path);
        $this->assertSame('compatibility.retrieve_medias', $j->matchedRoute);
    }

    #[Test]
    public function retrieveMediasError(): void
    {
        $this->mock->scenarios()->set('compatibility.retrieve_medias', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->faxes()->getMedia('FX1', 'missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.retrieve_medias', $j->matchedRoute);
    }

    #[Test]
    public function deleteFaxMediaSuccess(): void
    {
        $body = $this->client->compat()->faxes()->deleteMedia('FX1', 'ME1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame($this->base() . '/Faxes/FX1/Media/ME1', $j->path);
        $this->assertSame('compatibility.delete_fax_media', $j->matchedRoute);
    }

    #[Test]
    public function deleteFaxMediaError(): void
    {
        $this->mock->scenarios()->set('compatibility.delete_fax_media', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->faxes()->deleteMedia('FX1', 'missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.delete_fax_media', $j->matchedRoute);
    }

    #[Test]
    public function retrieveFaxSuccess(): void
    {
        $body = $this->client->compat()->faxes()->get('FX1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Faxes/FX1', $j->path);
        $this->assertSame('compatibility.retrieve_fax', $j->matchedRoute);
    }

    #[Test]
    public function retrieveFaxError(): void
    {
        $this->mock->scenarios()->set('compatibility.retrieve_fax', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->faxes()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.retrieve_fax', $j->matchedRoute);
    }

    #[Test]
    public function updateFaxSuccess(): void
    {
        $body = $this->client->compat()->faxes()->update('FX1', ['Status' => 'canceled']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base() . '/Faxes/FX1', $j->path);
        $this->assertSame('compatibility.update_fax', $j->matchedRoute);
    }

    #[Test]
    public function updateFaxError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_fax', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->faxes()->update('missing', ['Status' => 'canceled']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_fax', $j->matchedRoute);
    }

    #[Test]
    public function deleteFaxSuccess(): void
    {
        $body = $this->client->compat()->faxes()->delete('FX1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame($this->base() . '/Faxes/FX1', $j->path);
        $this->assertSame('compatibility.delete_fax', $j->matchedRoute);
    }

    #[Test]
    public function deleteFaxError(): void
    {
        $this->mock->scenarios()->set('compatibility.delete_fax', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->faxes()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.delete_fax', $j->matchedRoute);
    }

    // ==================================================================
    // Conferences (+ participants, recordings, streams)
    // ==================================================================

    #[Test]
    public function listAllConferencesSuccess(): void
    {
        $body = $this->client->compat()->conferences()->list();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Conferences', $j->path);
        $this->assertSame('compatibility.list_all_conferences', $j->matchedRoute);
    }

    #[Test]
    public function listAllConferencesError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_all_conferences', 500, ['error' => 'internal']);
        try {
            $this->client->compat()->conferences()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('compatibility.list_all_conferences', $j->matchedRoute);
    }

    #[Test]
    public function retrieveConferenceSuccess(): void
    {
        $body = $this->client->compat()->conferences()->get('CF1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Conferences/CF1', $j->path);
        $this->assertSame('compatibility.retrieve_conference', $j->matchedRoute);
    }

    #[Test]
    public function retrieveConferenceError(): void
    {
        $this->mock->scenarios()->set('compatibility.retrieve_conference', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->conferences()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.retrieve_conference', $j->matchedRoute);
    }

    #[Test]
    public function updateConferenceSuccess(): void
    {
        $body = $this->client->compat()->conferences()->update('CF1', ['Status' => 'completed']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base() . '/Conferences/CF1', $j->path);
        $this->assertSame('compatibility.update_conference', $j->matchedRoute);
    }

    #[Test]
    public function updateConferenceError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_conference', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->conferences()->update('missing', ['Status' => 'completed']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_conference', $j->matchedRoute);
    }

    #[Test]
    public function listAllParticipantsSuccess(): void
    {
        $body = $this->client->compat()->conferences()->listParticipants('CF1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Conferences/CF1/Participants', $j->path);
        $this->assertSame('compatibility.list_all_participants', $j->matchedRoute);
    }

    #[Test]
    public function listAllParticipantsError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_all_participants', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->conferences()->listParticipants('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.list_all_participants', $j->matchedRoute);
    }

    #[Test]
    public function retrieveParticipantSuccess(): void
    {
        $body = $this->client->compat()->conferences()->getParticipant('CF1', 'CA1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Conferences/CF1/Participants/CA1', $j->path);
        $this->assertSame('compatibility.retrieve_participant', $j->matchedRoute);
    }

    #[Test]
    public function retrieveParticipantError(): void
    {
        $this->mock->scenarios()->set('compatibility.retrieve_participant', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->conferences()->getParticipant('CF1', 'missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.retrieve_participant', $j->matchedRoute);
    }

    #[Test]
    public function updateParticipantSuccess(): void
    {
        $body = $this->client->compat()->conferences()->updateParticipant('CF1', 'CA1', ['Muted' => 'true']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base() . '/Conferences/CF1/Participants/CA1', $j->path);
        $this->assertSame('compatibility.update_participant', $j->matchedRoute);
    }

    #[Test]
    public function updateParticipantError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_participant', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->conferences()->updateParticipant('CF1', 'missing', ['Muted' => 'true']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_participant', $j->matchedRoute);
    }

    #[Test]
    public function deleteParticipantSuccess(): void
    {
        $body = $this->client->compat()->conferences()->removeParticipant('CF1', 'CA1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame($this->base() . '/Conferences/CF1/Participants/CA1', $j->path);
        $this->assertSame('compatibility.delete_participant', $j->matchedRoute);
    }

    #[Test]
    public function deleteParticipantError(): void
    {
        $this->mock->scenarios()->set('compatibility.delete_participant', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->conferences()->removeParticipant('CF1', 'missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.delete_participant', $j->matchedRoute);
    }

    #[Test]
    public function listConferenceRecordingsSuccess(): void
    {
        $body = $this->client->compat()->conferences()->listRecordings('CF1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Conferences/CF1/Recordings', $j->path);
        $this->assertSame('compatibility.list_conference_recordings', $j->matchedRoute);
    }

    #[Test]
    public function listConferenceRecordingsError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_conference_recordings', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->conferences()->listRecordings('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.list_conference_recordings', $j->matchedRoute);
    }

    #[Test]
    public function getConferenceRecordingSuccess(): void
    {
        $body = $this->client->compat()->conferences()->getRecording('CF1', 'RE1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Conferences/CF1/Recordings/RE1', $j->path);
        $this->assertSame('compatibility.get_conference_recording', $j->matchedRoute);
    }

    #[Test]
    public function getConferenceRecordingError(): void
    {
        $this->mock->scenarios()->set('compatibility.get_conference_recording', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->conferences()->getRecording('CF1', 'missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.get_conference_recording', $j->matchedRoute);
    }

    #[Test]
    public function updateConferenceRecordingSuccess(): void
    {
        $body = $this->client->compat()->conferences()->updateRecording('CF1', 'RE1', ['Status' => 'paused']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base() . '/Conferences/CF1/Recordings/RE1', $j->path);
        $this->assertSame('compatibility.update_conference_recording', $j->matchedRoute);
    }

    #[Test]
    public function updateConferenceRecordingError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_conference_recording', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->conferences()->updateRecording('CF1', 'missing', ['Status' => 'paused']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_conference_recording', $j->matchedRoute);
    }

    #[Test]
    public function deleteConferenceRecordingSuccess(): void
    {
        $body = $this->client->compat()->conferences()->deleteRecording('CF1', 'RE1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame($this->base() . '/Conferences/CF1/Recordings/RE1', $j->path);
        $this->assertSame('compatibility.delete_conference_recording', $j->matchedRoute);
    }

    #[Test]
    public function deleteConferenceRecordingError(): void
    {
        $this->mock->scenarios()->set('compatibility.delete_conference_recording', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->conferences()->deleteRecording('CF1', 'missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.delete_conference_recording', $j->matchedRoute);
    }

    #[Test]
    public function createConferenceStreamSuccess(): void
    {
        $body = $this->client->compat()->conferences()->startStream('CF1', ['Url' => 'wss://a.b/s']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base() . '/Conferences/CF1/Streams', $j->path);
        $this->assertSame('compatibility.create_conference_stream', $j->matchedRoute);
    }

    #[Test]
    public function createConferenceStreamError(): void
    {
        $this->mock->scenarios()->set('compatibility.create_conference_stream', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->conferences()->startStream('missing', ['Url' => 'wss://a.b/s']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.create_conference_stream', $j->matchedRoute);
    }

    #[Test]
    public function updateConferenceStreamSuccess(): void
    {
        $body = $this->client->compat()->conferences()->stopStream('CF1', 'ST1', ['Status' => 'stopped']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base() . '/Conferences/CF1/Streams/ST1', $j->path);
        $this->assertSame('compatibility.update_conference_stream', $j->matchedRoute);
    }

    #[Test]
    public function updateConferenceStreamError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_conference_stream', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->conferences()->stopStream('missing', 'ST1', ['Status' => 'stopped']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_conference_stream', $j->matchedRoute);
    }

    // ==================================================================
    // Queues (+ members)
    // ==================================================================

    #[Test]
    public function listQueuesSuccess(): void
    {
        $body = $this->client->compat()->queues()->list();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Queues', $j->path);
        $this->assertSame('compatibility.list_queues', $j->matchedRoute);
    }

    #[Test]
    public function listQueuesError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_queues', 500, ['error' => 'internal']);
        try {
            $this->client->compat()->queues()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('compatibility.list_queues', $j->matchedRoute);
    }

    #[Test]
    public function createQueueSuccess(): void
    {
        $body = $this->client->compat()->queues()->create(['FriendlyName' => 'support']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base() . '/Queues', $j->path);
        $this->assertSame('compatibility.create_queue', $j->matchedRoute);
    }

    #[Test]
    public function createQueueError(): void
    {
        $this->mock->scenarios()->set('compatibility.create_queue', 422, ['error' => 'bad']);
        try {
            $this->client->compat()->queues()->create(['FriendlyName' => '']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('compatibility.create_queue', $j->matchedRoute);
    }

    #[Test]
    public function retrieveQueueSuccess(): void
    {
        $body = $this->client->compat()->queues()->get('QU1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Queues/QU1', $j->path);
        $this->assertSame('compatibility.retrieve_queue', $j->matchedRoute);
    }

    #[Test]
    public function retrieveQueueError(): void
    {
        $this->mock->scenarios()->set('compatibility.retrieve_queue', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->queues()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.retrieve_queue', $j->matchedRoute);
    }

    #[Test]
    public function updateQueueSuccess(): void
    {
        $body = $this->client->compat()->queues()->update('QU1', ['FriendlyName' => 'renamed']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base() . '/Queues/QU1', $j->path);
        $this->assertSame('compatibility.update_queue', $j->matchedRoute);
    }

    #[Test]
    public function updateQueueError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_queue', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->queues()->update('missing', ['FriendlyName' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_queue', $j->matchedRoute);
    }

    #[Test]
    public function deleteQueueSuccess(): void
    {
        $body = $this->client->compat()->queues()->delete('QU1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame($this->base() . '/Queues/QU1', $j->path);
        $this->assertSame('compatibility.delete_queue', $j->matchedRoute);
    }

    #[Test]
    public function deleteQueueError(): void
    {
        $this->mock->scenarios()->set('compatibility.delete_queue', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->queues()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.delete_queue', $j->matchedRoute);
    }

    #[Test]
    public function listAllQueueMembersSuccess(): void
    {
        $body = $this->client->compat()->queues()->listMembers('QU1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Queues/QU1/Members', $j->path);
        $this->assertSame('compatibility.list_all_queue_members', $j->matchedRoute);
    }

    #[Test]
    public function listAllQueueMembersError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_all_queue_members', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->queues()->listMembers('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.list_all_queue_members', $j->matchedRoute);
    }

    #[Test]
    public function retrieveQueueMemberSuccess(): void
    {
        $body = $this->client->compat()->queues()->getMember('QU1', 'CA1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/Queues/QU1/Members/CA1', $j->path);
        $this->assertSame('compatibility.retrieve_queue_member', $j->matchedRoute);
    }

    #[Test]
    public function retrieveQueueMemberError(): void
    {
        $this->mock->scenarios()->set('compatibility.retrieve_queue_member', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->queues()->getMember('QU1', 'missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.retrieve_queue_member', $j->matchedRoute);
    }

    #[Test]
    public function updateQueueMemberSuccess(): void
    {
        $body = $this->client->compat()->queues()->dequeueMember('QU1', 'CA1', ['Url' => 'https://a.b/c']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base() . '/Queues/QU1/Members/CA1', $j->path);
        $this->assertSame('compatibility.update_queue_member', $j->matchedRoute);
    }

    #[Test]
    public function updateQueueMemberError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_queue_member', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->queues()->dequeueMember('QU1', 'missing', ['Url' => 'https://a.b/c']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_queue_member', $j->matchedRoute);
    }

    // ==================================================================
    // Phone numbers (incoming, imported, available)
    // ==================================================================

    #[Test]
    public function listIncomingPhoneNumbersSuccess(): void
    {
        $body = $this->client->compat()->phoneNumbers()->list();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/IncomingPhoneNumbers', $j->path);
        $this->assertSame('compatibility.list_incoming_phone_numbers', $j->matchedRoute);
    }

    #[Test]
    public function listIncomingPhoneNumbersError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_incoming_phone_numbers', 500, ['error' => 'internal']);
        try {
            $this->client->compat()->phoneNumbers()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('compatibility.list_incoming_phone_numbers', $j->matchedRoute);
    }

    #[Test]
    public function createIncomingPhoneNumberSuccess(): void
    {
        $body = $this->client->compat()->phoneNumbers()->purchase(['PhoneNumber' => '+15551112222']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base() . '/IncomingPhoneNumbers', $j->path);
        $this->assertSame('compatibility.create_incoming_phone_number', $j->matchedRoute);
    }

    #[Test]
    public function createIncomingPhoneNumberError(): void
    {
        $this->mock->scenarios()->set('compatibility.create_incoming_phone_number', 422, ['error' => 'bad']);
        try {
            $this->client->compat()->phoneNumbers()->purchase(['PhoneNumber' => '+1']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('compatibility.create_incoming_phone_number', $j->matchedRoute);
    }

    #[Test]
    public function retrieveIncomingPhoneNumberSuccess(): void
    {
        $body = $this->client->compat()->phoneNumbers()->get('PN1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/IncomingPhoneNumbers/PN1', $j->path);
        $this->assertSame('compatibility.retrieve_incoming_phone_number', $j->matchedRoute);
    }

    #[Test]
    public function retrieveIncomingPhoneNumberError(): void
    {
        $this->mock->scenarios()->set('compatibility.retrieve_incoming_phone_number', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->phoneNumbers()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.retrieve_incoming_phone_number', $j->matchedRoute);
    }

    #[Test]
    public function updateIncomingPhoneNumberSuccess(): void
    {
        $body = $this->client->compat()->phoneNumbers()->update('PN1', ['FriendlyName' => 'renamed']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base() . '/IncomingPhoneNumbers/PN1', $j->path);
        $this->assertSame('compatibility.update_incoming_phone_number', $j->matchedRoute);
    }

    #[Test]
    public function updateIncomingPhoneNumberError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_incoming_phone_number', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->phoneNumbers()->update('missing', ['FriendlyName' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_incoming_phone_number', $j->matchedRoute);
    }

    #[Test]
    public function deleteIncomingPhoneNumberSuccess(): void
    {
        $body = $this->client->compat()->phoneNumbers()->delete('PN1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame($this->base() . '/IncomingPhoneNumbers/PN1', $j->path);
        $this->assertSame('compatibility.delete_incoming_phone_number', $j->matchedRoute);
    }

    #[Test]
    public function deleteIncomingPhoneNumberError(): void
    {
        $this->mock->scenarios()->set('compatibility.delete_incoming_phone_number', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->phoneNumbers()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.delete_incoming_phone_number', $j->matchedRoute);
    }

    #[Test]
    public function createImportedPhoneNumberSuccess(): void
    {
        $body = $this->client->compat()->phoneNumbers()->importNumber(['PhoneNumber' => '+15551112222']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base() . '/ImportedPhoneNumbers', $j->path);
        $this->assertSame('compatibility.create_imported_phone_number', $j->matchedRoute);
    }

    #[Test]
    public function createImportedPhoneNumberError(): void
    {
        $this->mock->scenarios()->set('compatibility.create_imported_phone_number', 422, ['error' => 'bad']);
        try {
            $this->client->compat()->phoneNumbers()->importNumber(['PhoneNumber' => '+1']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('compatibility.create_imported_phone_number', $j->matchedRoute);
    }

    #[Test]
    public function listAvailablePhoneNumberResourcesSuccess(): void
    {
        $body = $this->client->compat()->phoneNumbers()->listAvailableCountries();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/AvailablePhoneNumbers', $j->path);
        $this->assertSame('compatibility.list_available_phone_number_resources', $j->matchedRoute);
    }

    #[Test]
    public function listAvailablePhoneNumberResourcesError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_available_phone_number_resources', 500, ['error' => 'internal']);
        try {
            $this->client->compat()->phoneNumbers()->listAvailableCountries();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('compatibility.list_available_phone_number_resources', $j->matchedRoute);
    }

    #[Test]
    public function searchLocalAvailablePhoneNumbersSuccess(): void
    {
        $body = $this->client->compat()->phoneNumbers()->searchLocal('US');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/AvailablePhoneNumbers/US/Local', $j->path);
        $this->assertSame('compatibility.search_local_available_phone_numbers', $j->matchedRoute);
    }

    #[Test]
    public function searchLocalAvailablePhoneNumbersError(): void
    {
        $this->mock->scenarios()->set('compatibility.search_local_available_phone_numbers', 500, ['error' => 'internal']);
        try {
            $this->client->compat()->phoneNumbers()->searchLocal('US');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('compatibility.search_local_available_phone_numbers', $j->matchedRoute);
    }

    #[Test]
    public function searchTollFreeAvailablePhoneNumbersSuccess(): void
    {
        $body = $this->client->compat()->phoneNumbers()->searchTollFree('US');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base() . '/AvailablePhoneNumbers/US/TollFree', $j->path);
        $this->assertSame('compatibility.search_toll_free_available_phone_numbers', $j->matchedRoute);
    }

    #[Test]
    public function searchTollFreeAvailablePhoneNumbersError(): void
    {
        $this->mock->scenarios()->set('compatibility.search_toll_free_available_phone_numbers', 500, ['error' => 'internal']);
        try {
            $this->client->compat()->phoneNumbers()->searchTollFree('US');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('compatibility.search_toll_free_available_phone_numbers', $j->matchedRoute);
    }
}
