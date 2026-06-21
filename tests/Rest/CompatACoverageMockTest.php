<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\REST\RestClient;
use SignalWire\REST\SignalWireRestError;

/**
 * Full success + error coverage for the COMPAT-A group of the LAML
 * (Twilio-compatible) REST surface: accounts/subprojects, applications,
 * cXML scripts (LamlBins), tokens, calls, per-call recordings + streams,
 * account-scoped recordings, and transcriptions.
 *
 * Each canonical route gets a SUCCESS test (real SDK call, in-body assertion
 * plus journal method/path/matchedRoute) and an ERROR test (scenario arms a
 * 4xx/5xx; SDK raises SignalWireRestError; journal records the hit + status).
 *
 * The LAML AccountSid is the per-test random project, so paths are built with
 * $this->project; for account get/update the {Sid} is also $this->project.
 */
class CompatACoverageMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;
    private string $project;

    private string $accounts = '/api/laml/2010-04-01/Accounts';
    private string $base;

    protected function setUp(): void
    {
        [$this->client, $this->mock, $this->project] = MockTest::scopedClient();
        $this->base = '/api/laml/2010-04-01/Accounts/' . $this->project;
    }

    // ===== accounts / subprojects ======================================

    #[Test]
    public function listAccountsSuccess(): void
    {
        $body = $this->client->compat()->accounts()->list();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->accounts, $j->path);
        $this->assertSame('compatibility.list_accounts', $j->matchedRoute);
    }

    #[Test]
    public function listAccountsError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_accounts', 500, ['error' => 'internal']);
        try {
            $this->client->compat()->accounts()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('compatibility.list_accounts', $j->matchedRoute);
    }

    #[Test]
    public function createSubprojectsSuccess(): void
    {
        $body = $this->client->compat()->accounts()->create(['FriendlyName' => 'Sub-A']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->accounts, $j->path);
        $this->assertSame('compatibility.create_subprojects', $j->matchedRoute);
    }

    #[Test]
    public function createSubprojectsError(): void
    {
        $this->mock->scenarios()->set('compatibility.create_subprojects', 422, ['error' => 'bad']);
        try {
            $this->client->compat()->accounts()->create(['FriendlyName' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('compatibility.create_subprojects', $j->matchedRoute);
    }

    #[Test]
    public function getAccountSuccess(): void
    {
        // For account get the {Sid} is the project itself.
        $body = $this->client->compat()->accounts()->get($this->project);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->accounts . '/' . $this->project, $j->path);
        $this->assertSame('compatibility.get_account', $j->matchedRoute);
    }

    #[Test]
    public function getAccountError(): void
    {
        $this->mock->scenarios()->set('compatibility.get_account', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->accounts()->get($this->project);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.get_account', $j->matchedRoute);
    }

    #[Test]
    public function updateAccountSuccess(): void
    {
        $body = $this->client->compat()->accounts()->update($this->project, ['FriendlyName' => 'Renamed']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->accounts . '/' . $this->project, $j->path);
        $this->assertSame('compatibility.update_account', $j->matchedRoute);
    }

    #[Test]
    public function updateAccountError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_account', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->accounts()->update($this->project, ['FriendlyName' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_account', $j->matchedRoute);
    }

    // ===== applications ================================================

    #[Test]
    public function listApplicationsSuccess(): void
    {
        $body = $this->client->compat()->applications()->list();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base . '/Applications', $j->path);
        $this->assertSame('compatibility.list_applications', $j->matchedRoute);
    }

    #[Test]
    public function listApplicationsError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_applications', 500, ['error' => 'internal']);
        try {
            $this->client->compat()->applications()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('compatibility.list_applications', $j->matchedRoute);
    }

    #[Test]
    public function createApplicationSuccess(): void
    {
        $body = $this->client->compat()->applications()->create(['FriendlyName' => 'App-A']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base . '/Applications', $j->path);
        $this->assertSame('compatibility.create_application', $j->matchedRoute);
    }

    #[Test]
    public function createApplicationError(): void
    {
        $this->mock->scenarios()->set('compatibility.create_application', 422, ['error' => 'bad']);
        try {
            $this->client->compat()->applications()->create(['FriendlyName' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('compatibility.create_application', $j->matchedRoute);
    }

    #[Test]
    public function getApplicationSuccess(): void
    {
        $body = $this->client->compat()->applications()->get('AP1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base . '/Applications/AP1', $j->path);
        $this->assertSame('compatibility.get_application', $j->matchedRoute);
    }

    #[Test]
    public function getApplicationError(): void
    {
        $this->mock->scenarios()->set('compatibility.get_application', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->applications()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.get_application', $j->matchedRoute);
    }

    #[Test]
    public function updateApplicationSuccess(): void
    {
        $body = $this->client->compat()->applications()->update('AP1', ['FriendlyName' => 'renamed']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base . '/Applications/AP1', $j->path);
        $this->assertSame('compatibility.update_application', $j->matchedRoute);
    }

    #[Test]
    public function updateApplicationError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_application', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->applications()->update('missing', ['FriendlyName' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_application', $j->matchedRoute);
    }

    #[Test]
    public function deleteApplicationSuccess(): void
    {
        $body = $this->client->compat()->applications()->delete('AP1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame($this->base . '/Applications/AP1', $j->path);
        $this->assertSame('compatibility.delete_application', $j->matchedRoute);
    }

    #[Test]
    public function deleteApplicationError(): void
    {
        $this->mock->scenarios()->set('compatibility.delete_application', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->applications()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.delete_application', $j->matchedRoute);
    }

    // ===== cXML scripts (LamlBins) =====================================

    #[Test]
    public function listCxmlScriptsSuccess(): void
    {
        $body = $this->client->compat()->lamlBins()->list();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base . '/LamlBins', $j->path);
        $this->assertSame('compatibility.list_cxml_scripts', $j->matchedRoute);
    }

    #[Test]
    public function listCxmlScriptsError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_cxml_scripts', 500, ['error' => 'internal']);
        try {
            $this->client->compat()->lamlBins()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('compatibility.list_cxml_scripts', $j->matchedRoute);
    }

    #[Test]
    public function createCxmlScriptSuccess(): void
    {
        $body = $this->client->compat()->lamlBins()->create(['Name' => 'bin-a', 'Contents' => '<Response/>']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base . '/LamlBins', $j->path);
        $this->assertSame('compatibility.create_cxml_script', $j->matchedRoute);
    }

    #[Test]
    public function createCxmlScriptError(): void
    {
        $this->mock->scenarios()->set('compatibility.create_cxml_script', 422, ['error' => 'bad']);
        try {
            $this->client->compat()->lamlBins()->create(['Name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('compatibility.create_cxml_script', $j->matchedRoute);
    }

    #[Test]
    public function retrieveCxmlScriptSuccess(): void
    {
        $body = $this->client->compat()->lamlBins()->get('LB1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base . '/LamlBins/LB1', $j->path);
        $this->assertSame('compatibility.retrieve_cxml_script', $j->matchedRoute);
    }

    #[Test]
    public function retrieveCxmlScriptError(): void
    {
        $this->mock->scenarios()->set('compatibility.retrieve_cxml_script', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->lamlBins()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.retrieve_cxml_script', $j->matchedRoute);
    }

    #[Test]
    public function updateCxmlScriptSuccess(): void
    {
        $body = $this->client->compat()->lamlBins()->update('LB1', ['Name' => 'renamed']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base . '/LamlBins/LB1', $j->path);
        $this->assertSame('compatibility.update_cxml_script', $j->matchedRoute);
    }

    #[Test]
    public function updateCxmlScriptError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_cxml_script', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->lamlBins()->update('missing', ['Name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_cxml_script', $j->matchedRoute);
    }

    #[Test]
    public function deleteCxmlScriptSuccess(): void
    {
        $body = $this->client->compat()->lamlBins()->delete('LB1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame($this->base . '/LamlBins/LB1', $j->path);
        $this->assertSame('compatibility.delete_cxml_script', $j->matchedRoute);
    }

    #[Test]
    public function deleteCxmlScriptError(): void
    {
        $this->mock->scenarios()->set('compatibility.delete_cxml_script', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->lamlBins()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.delete_cxml_script', $j->matchedRoute);
    }

    // ===== tokens ======================================================

    #[Test]
    public function createTokenSuccess(): void
    {
        $body = $this->client->compat()->tokens()->create(['name' => 'tok-a']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base . '/tokens', $j->path);
        $this->assertSame('compatibility.create_token', $j->matchedRoute);
    }

    #[Test]
    public function createTokenError(): void
    {
        $this->mock->scenarios()->set('compatibility.create_token', 422, ['error' => 'bad']);
        try {
            $this->client->compat()->tokens()->create(['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('compatibility.create_token', $j->matchedRoute);
    }

    #[Test]
    public function updateTokenSuccess(): void
    {
        $body = $this->client->compat()->tokens()->update('tok1', ['name' => 'renamed']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('PATCH', $j->method);
        $this->assertSame($this->base . '/tokens/tok1', $j->path);
        $this->assertSame('compatibility.update_token', $j->matchedRoute);
    }

    #[Test]
    public function updateTokenError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_token', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->tokens()->update('missing', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_token', $j->matchedRoute);
    }

    #[Test]
    public function deleteTokenSuccess(): void
    {
        $body = $this->client->compat()->tokens()->delete('tok1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame($this->base . '/tokens/tok1', $j->path);
        $this->assertSame('compatibility.delete_token', $j->matchedRoute);
    }

    #[Test]
    public function deleteTokenError(): void
    {
        $this->mock->scenarios()->set('compatibility.delete_token', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->tokens()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.delete_token', $j->matchedRoute);
    }

    // ===== calls =======================================================

    #[Test]
    public function listAllCallsSuccess(): void
    {
        $body = $this->client->compat()->calls()->list();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base . '/Calls', $j->path);
        $this->assertSame('compatibility.list_all_calls', $j->matchedRoute);
    }

    #[Test]
    public function listAllCallsError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_all_calls', 500, ['error' => 'internal']);
        try {
            $this->client->compat()->calls()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('compatibility.list_all_calls', $j->matchedRoute);
    }

    #[Test]
    public function createACallSuccess(): void
    {
        $body = $this->client->compat()->calls()->create(['To' => '+15551112222', 'From' => '+15553334444']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base . '/Calls', $j->path);
        $this->assertSame('compatibility.create_a_call', $j->matchedRoute);
    }

    #[Test]
    public function createACallError(): void
    {
        $this->mock->scenarios()->set('compatibility.create_a_call', 422, ['error' => 'bad']);
        try {
            $this->client->compat()->calls()->create(['To' => '+1', 'From' => '+1']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('compatibility.create_a_call', $j->matchedRoute);
    }

    #[Test]
    public function retrieveACallSuccess(): void
    {
        $body = $this->client->compat()->calls()->get('CA1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base . '/Calls/CA1', $j->path);
        $this->assertSame('compatibility.retrieve_a_call', $j->matchedRoute);
    }

    #[Test]
    public function retrieveACallError(): void
    {
        $this->mock->scenarios()->set('compatibility.retrieve_a_call', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->calls()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.retrieve_a_call', $j->matchedRoute);
    }

    #[Test]
    public function updateACallSuccess(): void
    {
        $body = $this->client->compat()->calls()->update('CA1', ['Status' => 'completed']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base . '/Calls/CA1', $j->path);
        $this->assertSame('compatibility.update_a_call', $j->matchedRoute);
    }

    #[Test]
    public function updateACallError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_a_call', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->calls()->update('missing', ['Status' => 'completed']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_a_call', $j->matchedRoute);
    }

    #[Test]
    public function deleteACallSuccess(): void
    {
        $body = $this->client->compat()->calls()->delete('CA1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame($this->base . '/Calls/CA1', $j->path);
        $this->assertSame('compatibility.delete_a_call', $j->matchedRoute);
    }

    #[Test]
    public function deleteACallError(): void
    {
        $this->mock->scenarios()->set('compatibility.delete_a_call', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->calls()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.delete_a_call', $j->matchedRoute);
    }

    // ===== per-call recordings + streams ===============================

    #[Test]
    public function createRecordingSuccess(): void
    {
        $body = $this->client->compat()->calls()->startRecording('CA1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base . '/Calls/CA1/Recordings', $j->path);
        $this->assertSame('compatibility.create_recording', $j->matchedRoute);
    }

    #[Test]
    public function createRecordingError(): void
    {
        $this->mock->scenarios()->set('compatibility.create_recording', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->calls()->startRecording('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.create_recording', $j->matchedRoute);
    }

    #[Test]
    public function updateRecordingSuccess(): void
    {
        $body = $this->client->compat()->calls()->updateRecording('CA1', 'RE1', ['Status' => 'paused']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base . '/Calls/CA1/Recordings/RE1', $j->path);
        $this->assertSame('compatibility.update_recording', $j->matchedRoute);
    }

    #[Test]
    public function updateRecordingError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_recording', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->calls()->updateRecording('missing', 'RE1', ['Status' => 'paused']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_recording', $j->matchedRoute);
    }

    #[Test]
    public function createStreamSuccess(): void
    {
        $body = $this->client->compat()->calls()->startStream('CA1', ['Url' => 'wss://a.b/s']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base . '/Calls/CA1/Streams', $j->path);
        $this->assertSame('compatibility.create_stream', $j->matchedRoute);
    }

    #[Test]
    public function createStreamError(): void
    {
        $this->mock->scenarios()->set('compatibility.create_stream', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->calls()->startStream('missing', ['Url' => 'wss://a.b/s']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.create_stream', $j->matchedRoute);
    }

    #[Test]
    public function updateStreamSuccess(): void
    {
        $body = $this->client->compat()->calls()->stopStream('CA1', 'ST1', ['Status' => 'stopped']);
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame($this->base . '/Calls/CA1/Streams/ST1', $j->path);
        $this->assertSame('compatibility.update_stream', $j->matchedRoute);
    }

    #[Test]
    public function updateStreamError(): void
    {
        $this->mock->scenarios()->set('compatibility.update_stream', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->calls()->stopStream('missing', 'ST1', ['Status' => 'stopped']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.update_stream', $j->matchedRoute);
    }

    // ===== account-scoped recordings (list/get/delete) =================

    #[Test]
    public function listRecordingsSuccess(): void
    {
        $body = $this->client->compat()->recordings()->list();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base . '/Recordings', $j->path);
        $this->assertSame('compatibility.list_recordings', $j->matchedRoute);
    }

    #[Test]
    public function listRecordingsError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_recordings', 500, ['error' => 'internal']);
        try {
            $this->client->compat()->recordings()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('compatibility.list_recordings', $j->matchedRoute);
    }

    #[Test]
    public function retrieveRecordingSuccess(): void
    {
        $body = $this->client->compat()->recordings()->get('RE1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base . '/Recordings/RE1', $j->path);
        $this->assertSame('compatibility.retrieve_recording', $j->matchedRoute);
    }

    #[Test]
    public function retrieveRecordingError(): void
    {
        $this->mock->scenarios()->set('compatibility.retrieve_recording', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->recordings()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.retrieve_recording', $j->matchedRoute);
    }

    #[Test]
    public function deleteRecordingSuccess(): void
    {
        $body = $this->client->compat()->recordings()->delete('RE1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame($this->base . '/Recordings/RE1', $j->path);
        $this->assertSame('compatibility.delete_recording', $j->matchedRoute);
    }

    #[Test]
    public function deleteRecordingError(): void
    {
        $this->mock->scenarios()->set('compatibility.delete_recording', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->recordings()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.delete_recording', $j->matchedRoute);
    }

    // ===== transcriptions (list/get/delete) ============================

    #[Test]
    public function listTranscriptionsSuccess(): void
    {
        $body = $this->client->compat()->transcriptions()->list();
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base . '/Transcriptions', $j->path);
        $this->assertSame('compatibility.list_transcriptions', $j->matchedRoute);
    }

    #[Test]
    public function listTranscriptionsError(): void
    {
        $this->mock->scenarios()->set('compatibility.list_transcriptions', 500, ['error' => 'internal']);
        try {
            $this->client->compat()->transcriptions()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('compatibility.list_transcriptions', $j->matchedRoute);
    }

    #[Test]
    public function retrieveTranscriptionSuccess(): void
    {
        $body = $this->client->compat()->transcriptions()->get('TR1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame($this->base . '/Transcriptions/TR1', $j->path);
        $this->assertSame('compatibility.retrieve_transcription', $j->matchedRoute);
    }

    #[Test]
    public function retrieveTranscriptionError(): void
    {
        $this->mock->scenarios()->set('compatibility.retrieve_transcription', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->transcriptions()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.retrieve_transcription', $j->matchedRoute);
    }

    #[Test]
    public function deleteTranscriptionSuccess(): void
    {
        $body = $this->client->compat()->transcriptions()->delete('TR1');
        $this->assertIsArray($body);
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame($this->base . '/Transcriptions/TR1', $j->path);
        $this->assertSame('compatibility.delete_transcription', $j->matchedRoute);
    }

    #[Test]
    public function deleteTranscriptionError(): void
    {
        $this->mock->scenarios()->set('compatibility.delete_transcription', 404, ['error' => 'not found']);
        try {
            $this->client->compat()->transcriptions()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('compatibility.delete_transcription', $j->matchedRoute);
    }
}
