<?php

declare(strict_types=1);

namespace SignalWire\REST;

/**
 * Stand-in HTTP client for a credential a RestClient was not given: every
 * request throws ``\InvalidArgumentException`` naming the missing credential,
 * before anything is sent. Mirrors the reference's private
 * ``_MissingCredentialHttp``; constructed only by RestClient.
 *
 * @internal
 */
final class MissingCredentialHttpClient extends HttpClient
{
    /** @param non-empty-string $baseUrl */
    public function __construct(string $baseUrl, private readonly string $missing)
    {
        parent::__construct('', '', $baseUrl);
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,string>|null $headers
     * @return array<string,mixed>
     */
    public function get(
        string $path,
        array $params = [],
        ?RequestOptions $requestOptions = null,
        ?array $headers = null
    ): array {
        throw new \InvalidArgumentException($this->missing);
    }

    /**
     * @param array<string,mixed>|null $params
     * @param array<string,string>|null $headers
     */
    public function getText(
        string $path,
        ?array $params = null,
        ?RequestOptions $requestOptions = null,
        ?array $headers = null
    ): string {
        throw new \InvalidArgumentException($this->missing);
    }

    /** @param array<string,mixed>|null $params */
    public function getRedirectLocation(
        string $path,
        ?array $params = null,
        ?RequestOptions $requestOptions = null
    ): string {
        throw new \InvalidArgumentException($this->missing);
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,mixed>|null $params
     * @param array<string,string>|null $headers
     * @return array<string,mixed>
     */
    public function post(
        string $path,
        ?array $body = null,
        ?array $params = null,
        ?RequestOptions $requestOptions = null,
        ?array $headers = null
    ): array {
        throw new \InvalidArgumentException($this->missing);
    }

    /**
     * @param array<string,mixed>|null $data
     * @return array<string,mixed>
     */
    public function put(string $path, ?array $data = null, ?RequestOptions $requestOptions = null): array
    {
        throw new \InvalidArgumentException($this->missing);
    }

    /**
     * @param array<string,mixed>|null $data
     * @return array<string,mixed>
     */
    public function patch(string $path, ?array $data = null, ?RequestOptions $requestOptions = null): array
    {
        throw new \InvalidArgumentException($this->missing);
    }

    /** @return array<string,mixed> */
    public function delete(string $path, ?RequestOptions $requestOptions = null): array
    {
        throw new \InvalidArgumentException($this->missing);
    }

    /**
     * @param array<string,mixed> $params
     * @return \Generator<int,array<string,mixed>>
     */
    public function listAll(string $path, array $params = []): \Generator
    {
        throw new \InvalidArgumentException($this->missing);
    }
}
