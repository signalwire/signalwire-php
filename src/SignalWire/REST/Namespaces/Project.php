<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Project API namespace — currently exposes the project tokens sub-resource.
 */
class Project
{
    private HttpClient $http;
    private ProjectTokens $tokens;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
        $this->tokens = new ProjectTokens($http);
    }

    public function tokens(): ProjectTokens
    {
        return $this->tokens;
    }

    public function getHttp(): HttpClient
    {
        return $this->http;
    }
}
