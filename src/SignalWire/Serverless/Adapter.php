<?php

declare(strict_types=1);

namespace SignalWire\Serverless;

/**
 * Auto-detect and handle serverless environments (Lambda, GCF, Azure, CGI)
 * or fall back to the built-in PHP server.
 */
class Adapter
{
    /**
     * Detect the current runtime environment.
     *
     * @return string One of 'lambda', 'gcf', 'azure', 'cgi', or 'server'.
     */
    public static function detect(): string
    {
        if (getenv('AWS_LAMBDA_FUNCTION_NAME') !== false) {
            return 'lambda';
        }

        if (getenv('FUNCTION_TARGET') !== false || getenv('K_SERVICE') !== false) {
            return 'gcf';
        }

        if (getenv('AZURE_FUNCTIONS_ENVIRONMENT') !== false) {
            return 'azure';
        }

        if (isset($_SERVER['GATEWAY_INTERFACE']) || getenv('GATEWAY_INTERFACE') !== false) {
            return 'cgi';
        }

        return 'server';
    }

    /**
     * Handle an AWS Lambda (API Gateway) invocation.
     *
     * Extracts method, path, headers, and body from the API Gateway event
     * format, calls agent->handleRequest(), and returns an API Gateway
     * compatible response.
     *
     * @param object $agent   An AgentBase or Service instance with handleRequest().
     * @param array  $event   The API Gateway event payload.
     * @param object $context The Lambda context object.
     * @return array API Gateway response format {statusCode, headers, body}.
     */
    public static function handleLambda(object $agent, array $event, object $context): array
    {
        $method = strtoupper($event['httpMethod'] ?? $event['requestContext']['http']['method'] ?? 'GET');
        $path   = $event['path'] ?? $event['rawPath'] ?? '/';
        $body   = $event['body'] ?? null;

        // Decode base64-encoded bodies
        if ($body !== null && ($event['isBase64Encoded'] ?? false)) {
            $body = base64_decode($body, true);
            if ($body === false) {
                $body = null;
            }
        }

        // Normalise headers to mixed-case keys
        $headers = [];
        $rawHeaders = $event['headers'] ?? [];
        foreach ($rawHeaders as $key => $value) {
            $headers[$key] = $value;
        }

        [$status, $responseHeaders, $responseBody] = $agent->handleRequest($method, $path, $headers, $body);

        return [
            'statusCode' => $status,
            'headers'    => $responseHeaders,
            'body'       => $responseBody,
        ];
    }

    /**
     * Handle a Google Cloud Function invocation.
     *
     * Reads from php://input and $_SERVER, calls agent->handleRequest(),
     * then outputs headers and body directly to the response stream.
     *
     * @param object $agent An AgentBase or Service instance with handleRequest().
     */
    public static function handleGcf(object $agent): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path   = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '/';

        // Strip query string from path
        $qPos = strpos($path, '?');
        if ($qPos !== false) {
            $path = substr($path, 0, $qPos);
        }

        $body = file_get_contents('php://input') ?: null;
        if ($body === '') {
            $body = null;
        }

        $headers = self::extractServerHeaders();

        [$status, $responseHeaders, $responseBody] = $agent->handleRequest($method, $path, $headers, $body);

        http_response_code($status);
        foreach ($responseHeaders as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $responseBody;
    }

    /**
     * Handle an Azure Functions invocation.
     *
     * Extracts method, path, headers, and body from the Azure request
     * array, calls agent->handleRequest(), and returns an Azure-compatible
     * response array.
     *
     * @param object $agent   An AgentBase or Service instance with handleRequest().
     * @param array  $request The Azure Functions HTTP request array.
     * @return array Azure response format {status, headers, body}.
     */
    public static function handleAzure(object $agent, array $request): array
    {
        $method = strtoupper($request['method'] ?? $request['Method'] ?? 'GET');
        $path   = $request['url'] ?? $request['Url'] ?? '/';

        // Parse the URL to extract just the path
        $parsed = parse_url($path);
        $path = $parsed['path'] ?? '/';

        $body    = $request['body'] ?? $request['Body'] ?? null;
        $headers = $request['headers'] ?? $request['Headers'] ?? [];

        [$status, $responseHeaders, $responseBody] = $agent->handleRequest($method, $path, $headers, $body);

        return [
            'status'  => $status,
            'headers' => $responseHeaders,
            'body'    => $responseBody,
        ];
    }

    /**
     * Handle a CGI/FastCGI invocation.
     *
     * Reads REQUEST_METHOD, PATH_INFO, CONTENT_TYPE from $_SERVER,
     * reads body from php://input, parses headers from HTTP_* env vars,
     * and outputs the status line, headers, and body to stdout.
     *
     * @param object $agent An AgentBase or Service instance with handleRequest().
     */
    public static function handleCgi(object $agent): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path   = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '/';

        // Strip query string from path
        $qPos = strpos($path, '?');
        if ($qPos !== false) {
            $path = substr($path, 0, $qPos);
        }

        $body = file_get_contents('php://input') ?: null;
        if ($body === '') {
            $body = null;
        }

        $headers = self::extractServerHeaders();

        [$status, $responseHeaders, $responseBody] = $agent->handleRequest($method, $path, $headers, $body);

        // Output CGI status line
        $statusText = self::statusText($status);
        echo "Status: {$status} {$statusText}\r\n";

        // Output headers
        foreach ($responseHeaders as $name => $value) {
            echo "{$name}: {$value}\r\n";
        }

        // Blank line separating headers from body
        echo "\r\n";

        // Output body
        echo $responseBody;
    }

    /**
     * Auto-detect the runtime environment and serve the agent.
     *
     * For serverless environments, calls the appropriate handler.
     * For 'server', calls agent->run().
     *
     * @param object $agent An AgentBase or Service instance.
     */
    public static function serve(object $agent): void
    {
        $env = self::detect();

        switch ($env) {
            case 'lambda':
                // Lambda requires an event/context from the runtime API;
                // in a real deployment this would be invoked by the runtime.
                // Here we provide a minimal entrypoint that reads from stdin.
                $input = file_get_contents('php://input') ?: '{}';
                $event = json_decode($input, true) ?? [];
                $context = new \stdClass();
                $response = self::handleLambda($agent, $event, $context);
                echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            case 'gcf':
                self::handleGcf($agent);
                break;

            case 'azure':
                $input = file_get_contents('php://input') ?: '{}';
                $request = json_decode($input, true) ?? [];
                $response = self::handleAzure($agent, $request);
                echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            case 'cgi':
                self::handleCgi($agent);
                break;

            default:
                $agent->run();
                break;
        }
    }

    /**
     * Extract HTTP headers from $_SERVER (HTTP_* variables).
     *
     * @return array<string, string> Header name => value.
     */
    private static function extractServerHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                // HTTP_ACCEPT_LANGUAGE => Accept-Language
                $name = str_replace('_', '-', substr($key, 5));
                $name = implode('-', array_map('ucfirst', explode('-', strtolower($name))));
                $headers[$name] = $value;
            }
        }

        // CONTENT_TYPE and CONTENT_LENGTH are not prefixed with HTTP_
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * Return a standard HTTP status text for a given status code.
     */
    private static function statusText(int $code): string
    {
        $texts = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            413 => 'Payload Too Large',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];

        return $texts[$code] ?? 'Unknown';
    }
}
