<?php

declare(strict_types=1);

namespace SignalWire\Serverless;

use SignalWire\SWML\RequestHandlerLike;

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
     *                For a typed result, see {@see detectMode()}.
     */
    public static function detect(): string
    {
        return self::detectMode()->value;
    }

    /**
     * Detect the current runtime environment as a typed {@see ExecutionMode}.
     *
     * The typed counterpart to {@see detect()}: same detection logic and
     * precedence, but returns the enum (autocompletion, exhaustive `match`,
     * `->isServerless()`) instead of a bare string. `detectMode()->value`
     * equals `detect()`.
     */
    public static function detectMode(): ExecutionMode
    {
        if (getenv('AWS_LAMBDA_FUNCTION_NAME') !== false) {
            return ExecutionMode::Lambda;
        }

        if (getenv('FUNCTION_TARGET') !== false || getenv('K_SERVICE') !== false) {
            return ExecutionMode::Gcf;
        }

        if (getenv('AZURE_FUNCTIONS_ENVIRONMENT') !== false) {
            return ExecutionMode::Azure;
        }

        if (isset($_SERVER['GATEWAY_INTERFACE']) || getenv('GATEWAY_INTERFACE') !== false) {
            return ExecutionMode::Cgi;
        }

        return ExecutionMode::Server;
    }

    /**
     * Handle an AWS Lambda (API Gateway) invocation.
     *
     * Extracts method, path, headers, and body from the API Gateway event
     * format, calls agent->handleRequest(), and returns an API Gateway
     * compatible response.
     *
     * @param RequestHandlerLike $agent An AgentBase or Service request handler.
     * @param array<string,mixed> $event   The API Gateway event payload.
     * @param object $context The Lambda context object.
     * @return array{statusCode: int, headers: array<string,string>, body: string} API Gateway response format.
     */
    public static function handleLambda(RequestHandlerLike $agent, array $event, object $context): array
    {
        $requestContext = $event['requestContext'] ?? null;
        $ctxMethod = null;
        if (is_array($requestContext)) {
            $http = $requestContext['http'] ?? null;
            if (is_array($http)) {
                $ctxMethod = $http['method'] ?? null;
            }
        }
        $method = strtoupper(self::asString($event['httpMethod'] ?? $ctxMethod ?? 'GET', 'GET'));
        $path   = self::asString($event['path'] ?? $event['rawPath'] ?? '/', '/');

        $rawBody = $event['body'] ?? null;
        $body    = is_string($rawBody) ? $rawBody : null;

        // Decode base64-encoded bodies
        if ($body !== null && ($event['isBase64Encoded'] ?? false)) {
            $decoded = base64_decode($body, true);
            $body = $decoded === false ? null : $decoded;
        }

        // Normalise headers to string values.
        $headers = [];
        $rawHeaders = $event['headers'] ?? [];
        if (is_array($rawHeaders)) {
            foreach ($rawHeaders as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $headers[$key] = $value;
                }
            }
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
     * @param RequestHandlerLike $agent An AgentBase or Service request handler.
     */
    public static function handleGcf(RequestHandlerLike $agent): void
    {
        $method = strtoupper(self::asString($_SERVER['REQUEST_METHOD'] ?? 'GET', 'GET'));
        $path   = self::asString($_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '/', '/');

        // Strip query string from path
        $qPos = strpos($path, '?');
        if ($qPos !== false) {
            $path = substr($path, 0, $qPos);
        }

        // file_get_contents(...) ?: null already collapses an empty body to null.
        $body = file_get_contents('php://input') ?: null;

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
     * @param RequestHandlerLike $agent An AgentBase or Service request handler.
     * @param array<string,mixed> $request The Azure Functions HTTP request array.
     * @return array{status: int, headers: array<string,string>, body: string} Azure response format.
     */
    public static function handleAzure(RequestHandlerLike $agent, array $request): array
    {
        $method = strtoupper(self::asString($request['method'] ?? $request['Method'] ?? 'GET', 'GET'));
        $url    = self::asString($request['url'] ?? $request['Url'] ?? '/', '/');

        // Parse the URL to extract just the path
        $parsed = parse_url($url);
        $path = (is_array($parsed) && isset($parsed['path'])) ? $parsed['path'] : '/';

        $rawBody = $request['body'] ?? $request['Body'] ?? null;
        $body    = is_string($rawBody) ? $rawBody : null;

        $rawHeaders = $request['headers'] ?? $request['Headers'] ?? [];
        $headers = [];
        if (is_array($rawHeaders)) {
            foreach ($rawHeaders as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $headers[$key] = $value;
                }
            }
        }

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
     * @param RequestHandlerLike $agent An AgentBase or Service request handler.
     */
    public static function handleCgi(RequestHandlerLike $agent): void
    {
        $method = self::asString($_SERVER['REQUEST_METHOD'] ?? 'GET', 'GET');
        $path   = self::asString($_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '/', '/');

        // Strip query string from path
        $qPos = strpos($path, '?');
        if ($qPos !== false) {
            $path = substr($path, 0, $qPos);
        }

        // file_get_contents(...) ?: null already collapses an empty body to null.
        $body = file_get_contents('php://input') ?: null;

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
     * @param RequestHandlerLike $agent An AgentBase or Service request handler.
     * @param ExecutionMode|string|null $mode Optional explicit mode override.
     *        Pass an {@see ExecutionMode} (typed) or its backing string
     *        ('lambda'/'gcf'/'azure'/'cgi'/'server', for parity) to pin the
     *        dispatch instead of auto-detecting via {@see detectMode()}. An
     *        out-of-set string raises \ValueError. Defaults to null
     *        (auto-detect), preserving the original single-argument behaviour.
     */
    public static function serve(RequestHandlerLike $agent, ExecutionMode|string|null $mode = null): void
    {
        $resolved = $mode === null ? self::detectMode() : ExecutionMode::coerce($mode);

        switch ($resolved) {
            case ExecutionMode::Lambda:
                // Lambda requires an event/context from the runtime API;
                // in a real deployment this would be invoked by the runtime.
                // Here we provide a minimal entrypoint that reads from stdin.
                $input = file_get_contents('php://input') ?: '{}';
                $event = self::decodeJsonObject($input);
                $context = new \stdClass();
                $response = self::handleLambda($agent, $event, $context);
                echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            case ExecutionMode::Gcf:
                self::handleGcf($agent);
                break;

            case ExecutionMode::Azure:
                $input = file_get_contents('php://input') ?: '{}';
                $request = self::decodeJsonObject($input);
                $response = self::handleAzure($agent, $request);
                echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            case ExecutionMode::Cgi:
                self::handleCgi($agent);
                break;

            case ExecutionMode::Server:
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
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
                // HTTP_ACCEPT_LANGUAGE => Accept-Language
                $name = str_replace('_', '-', substr($key, 5));
                $name = implode('-', array_map('ucfirst', explode('-', strtolower($name))));
                $headers[$name] = $value;
            }
        }

        // CONTENT_TYPE and CONTENT_LENGTH are not prefixed with HTTP_
        $contentType = $_SERVER['CONTENT_TYPE'] ?? null;
        if (is_string($contentType)) {
            $headers['Content-Type'] = $contentType;
        }
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        if (is_string($contentLength)) {
            $headers['Content-Length'] = $contentLength;
        }

        return $headers;
    }

    /**
     * Coerce a possibly-mixed value to a string, falling back to $default
     * for non-string/non-scalar inputs.
     */
    private static function asString(mixed $value, string $default): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return $default;
    }

    /**
     * Decode a JSON string to a string-keyed array, returning [] when the
     * payload is not a JSON object.
     *
     * @return array<string, mixed>
     */
    private static function decodeJsonObject(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }
        return $out;
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
