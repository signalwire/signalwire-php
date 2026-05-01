<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Datasphere API namespace — exposes the documents sub-resource.
 */
class Datasphere
{
    private HttpClient $http;
    private DatasphereDocuments $documents;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
        $this->documents = new DatasphereDocuments($http);
    }

    public function documents(): DatasphereDocuments
    {
        return $this->documents;
    }

    public function getHttp(): HttpClient
    {
        return $this->http;
    }
}
