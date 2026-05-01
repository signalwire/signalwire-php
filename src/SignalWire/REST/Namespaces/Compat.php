<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;
use SignalWire\REST\HttpClient;

/**
 * Compatibility (Twilio-compatible LAML) API namespace.
 *
 * Mounted under ``/api/laml/2010-04-01/Accounts/{AccountSid}`` and exposes
 * the LAML-flavoured REST surface (Accounts, Calls, Messages, Faxes,
 * Conferences, IncomingPhoneNumbers + AvailablePhoneNumbers, Applications,
 * LamlBins, Queues, Recordings, Transcriptions, tokens) as object-style
 * sub-resources to mirror the Python ``CompatNamespace`` class.
 */
class Compat
{
    private HttpClient $http;
    private string $accountSid;
    private string $accountBase;

    private CompatAccounts $accounts;
    private CompatCalls $calls;
    private CompatMessages $messages;
    private CompatFaxes $faxes;
    private CompatConferences $conferences;
    private CompatPhoneNumbers $phoneNumbers;
    private CompatApplications $applications;
    private CompatLamlBins $lamlBins;
    private CompatQueues $queues;
    private CompatRecordings $recordings;
    private CompatTranscriptions $transcriptions;
    private CompatTokens $tokens;

    public function __construct(HttpClient $http, string $accountSid)
    {
        $this->http = $http;
        $this->accountSid = $accountSid;
        $this->accountBase = '/api/laml/2010-04-01/Accounts/' . $accountSid;

        $this->accounts = new CompatAccounts($http);
        $this->calls = new CompatCalls($http, $this->accountBase . '/Calls');
        $this->messages = new CompatMessages($http, $this->accountBase . '/Messages');
        $this->faxes = new CompatFaxes($http, $this->accountBase . '/Faxes');
        $this->conferences = new CompatConferences($http, $this->accountBase . '/Conferences');
        $this->phoneNumbers = new CompatPhoneNumbers($http, $this->accountBase . '/IncomingPhoneNumbers');
        $this->applications = new CompatApplications($http, $this->accountBase . '/Applications');
        $this->lamlBins = new CompatLamlBins($http, $this->accountBase . '/LamlBins');
        $this->queues = new CompatQueues($http, $this->accountBase . '/Queues');
        $this->recordings = new CompatRecordings($http, $this->accountBase . '/Recordings');
        $this->transcriptions = new CompatTranscriptions($http, $this->accountBase . '/Transcriptions');
        $this->tokens = new CompatTokens($http, $this->accountBase . '/tokens');
    }

    public function getAccountSid(): string
    {
        return $this->accountSid;
    }

    public function getAccountBase(): string
    {
        return $this->accountBase;
    }

    public function getHttp(): HttpClient
    {
        return $this->http;
    }

    public function accounts(): CompatAccounts
    {
        return $this->accounts;
    }

    public function calls(): CompatCalls
    {
        return $this->calls;
    }

    public function messages(): CompatMessages
    {
        return $this->messages;
    }

    public function faxes(): CompatFaxes
    {
        return $this->faxes;
    }

    public function conferences(): CompatConferences
    {
        return $this->conferences;
    }

    public function phoneNumbers(): CompatPhoneNumbers
    {
        return $this->phoneNumbers;
    }

    public function applications(): CompatApplications
    {
        return $this->applications;
    }

    public function lamlBins(): CompatLamlBins
    {
        return $this->lamlBins;
    }

    public function queues(): CompatQueues
    {
        return $this->queues;
    }

    public function recordings(): CompatRecordings
    {
        return $this->recordings;
    }

    public function transcriptions(): CompatTranscriptions
    {
        return $this->transcriptions;
    }

    public function tokens(): CompatTokens
    {
        return $this->tokens;
    }
}
