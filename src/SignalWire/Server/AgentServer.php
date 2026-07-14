<?php

declare(strict_types=1);

namespace SignalWire\Server;

use SignalWire\Agent\AgentBase;
use SignalWire\Logging\Logger;

class AgentServer
{
    protected string $host;
    protected int $port;
    protected string $logLevel;

    /** @var array<string, AgentBase> Route => Agent */
    protected array $agents = [];

    // SIP routing
    protected bool $sipRoutingEnabled = false;
    protected string $sipRoute = '/sip';
    protected bool $sipAutoMap = true;

    /** @var array<string, string> SIP username => route */
    protected array $sipUsernameMapping = [];

    /** @var array<string, string> URL prefix => directory path */
    protected array $staticRoutes = [];

    protected Logger $logger;

    /** @var array<string, string> Extension => MIME type */
    private const MIME_TYPES = [
        'html'  => 'text/html',
        'htm'   => 'text/html',
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'json'  => 'application/json',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'txt'   => 'text/plain',
        'pdf'   => 'application/pdf',
        'xml'   => 'application/xml',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'eot'   => 'application/vnd.ms-fontobject',
    ];

    /**
     * @param string|null $host
     * @param int|null $port
     * @param string $logLevel
     */
    public function __construct(
        ?string $host = null,
        ?int $port = null,
        string $logLevel = 'info'
    ) {
        $this->host     = $host ?? '0.0.0.0';
        if ($port !== null) {
            $this->port = $port;
        } else {
            $envPort = $_ENV['PORT'] ?? getenv('PORT');
            $this->port = (is_string($envPort) || is_int($envPort)) && (int) $envPort !== 0
                ? (int) $envPort
                : 3000;
        }
        $this->logLevel = $logLevel;
        $this->logger   = Logger::getLogger('agent_server');
    }

    // ======================================================================
    //  Agent Registration
    // ======================================================================

    /**
     * Register an agent at a route.
     *
     * @throws \RuntimeException If the route is already registered.
     */
    public function register(AgentBase $agent, ?string $route = null): self
    {
        $route = $route ?? $agent->getRoute();
        $route = $this->normalizeRoute($route);

        if (isset($this->agents[$route])) {
            throw new \RuntimeException("Route '{$route}' is already registered");
        }

        $this->agents[$route] = $agent;

        return $this;
    }

    /**
     * Register a routing callback across all registered agents.
     *
     * Mirrors Python `AgentServer.register_global_routing_callback(callback_fn,
     * path)`: normalizes the path and installs the callback on every agent at
     * that path, so unified routing logic applies uniformly. New agents
     * registered after this call are not retroactively updated (matching the
     * reference, which iterates the current agent set).
     *
     * @param callable $callbackFn fn(array $requestData, array $headers): ?string
     * @param string   $path       Path to register the callback at.
     */
    public function registerGlobalRoutingCallback(callable $callbackFn, string $path): void
    {
        // Normalize the path (leading slash, no trailing slash).
        if (!str_starts_with($path, '/')) {
            $path = "/{$path}";
        }
        $path = rtrim($path, '/');

        foreach ($this->agents as $agent) {
            // Service::registerRoutingCallback takes (path, callback).
            $agent->registerRoutingCallback($path, $callbackFn);
        }

        $this->logger->info("Registered global routing callback at {$path} on all agents");
    }

    /**
     * Unregister an agent from a route.
     */
    public function unregister(string $route): self
    {
        $route = $this->normalizeRoute($route);
        unset($this->agents[$route]);

        return $this;
    }

    /**
     * Get all registered routes (sorted).
     *
     * @return list<string>
     */
    public function getAgents(): array
    {
        $routes = array_keys($this->agents);
        sort($routes);
        return $routes;
    }

    /**
     * Get an agent by route.
     */
    public function getAgent(string $route): ?AgentBase
    {
        $route = $this->normalizeRoute($route);
        return $this->agents[$route] ?? null;
    }

    // ======================================================================
    //  SIP Routing
    // ======================================================================

    /**
     * Set up central SIP-based routing for the server.
     *
     * Mirrors Python's AgentServer.setup_sip_routing(route, auto_map). When
     * called with no args, defaults match Python: route="/sip", auto_map=true.
     *
     * @param string $route   Path for SIP routing (default "/sip"). Leading
     *                        slash is added if missing; trailing slash is
     *                        stripped.
     * @param bool   $auto_map If true, existing agents have their SIP
     *                        usernames auto-derived from their route.
     */
    public function setupSipRouting(string $route = '/sip', bool $auto_map = true): self
    {
        if ($this->sipRoutingEnabled) {
            $this->logger->warn('sip_routing_already_enabled');
            return $this;
        }

        if (!str_starts_with($route, '/')) {
            $route = '/' . $route;
        }
        $route = rtrim($route, '/');
        if ($route === '') {
            $route = '/sip';
        }

        $this->sipRoutingEnabled = true;
        $this->sipRoute = $route;
        $this->sipAutoMap = $auto_map;

        if ($auto_map) {
            foreach ($this->agents as $agentRoute => $_agent) {
                // Strip leading slash, lowercase — mirrors Python's auto-map
                $username = ltrim($agentRoute, '/');
                if ($username !== '') {
                    $this->sipUsernameMapping[strtolower($username)] = $agentRoute;
                }
            }
        }

        return $this;
    }

    public function getSipRoute(): string
    {
        return $this->sipRoute;
    }

    public function getSipAutoMap(): bool
    {
        return $this->sipAutoMap;
    }

    /**
     * Map a SIP username to a route.
     */
    public function registerSipUsername(string $username, string $route): self
    {
        $route = $this->normalizeRoute($route);
        // Python parity: the mapping is keyed by the lowercased username (store
        // AND lookup case-fold), so "Bob" and "bob" resolve to the same route.
        $this->sipUsernameMapping[strtolower($username)] = $route;
        return $this;
    }

    /**
     * Check if SIP routing is enabled.
     */
    public function isSipRoutingEnabled(): bool
    {
        return $this->sipRoutingEnabled;
    }

    /**
     * Get the SIP username mapping.
     *
     * @return array<string, string>
     */
    public function getSipUsernameMapping(): array
    {
        return $this->sipUsernameMapping;
    }

    // ======================================================================
    //  Static File Serving
    // ======================================================================

    /**
     * Serve static files from a directory under a URL prefix.
     *
     * Mirrors the Python reference's ``serve_static_files`` (and the go/java/ts/…
     * ports' serveStaticFiles / serve_static_files) so the method name is
     * consistent across the matrix.
     *
     * @throws \RuntimeException If the directory does not exist.
     */
    public function serveStaticFiles(string $directory, string $urlPrefix): self
    {
        $realDir = realpath($directory);
        if ($realDir === false || !is_dir($realDir)) {
            throw new \RuntimeException("Static directory '{$directory}' does not exist");
        }

        $urlPrefix = $this->normalizeRoute($urlPrefix);
        $this->staticRoutes[$urlPrefix] = $realDir;

        return $this;
    }

    /**
     * Back-compat alias for {@see serveStaticFiles()}. Retained so existing
     * callers keep working after the method was renamed to match the reference.
     *
     * @throws \RuntimeException If the directory does not exist.
     */
    public function serveStatic(string $directory, string $urlPrefix): self
    {
        return $this->serveStaticFiles($directory, $urlPrefix);
    }

    // ======================================================================
    //  Request Handling
    // ======================================================================

    /**
     * Handle an HTTP request and return [status, headers, body].
     *
     * @param array<string, string> $headers
     * @return array{int, array<string, string>, string}
     */
    public function handleRequest(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
    ): array {
        $path = $this->normalizePath($path);

        // Health endpoint (no auth)
        if ($path === '/health') {
            $agentNames = [];
            foreach ($this->getAgents() as $route) {
                $agentNames[] = $this->agents[$route]->getName();
            }
            return $this->jsonResponse(200, [
                'status' => 'healthy',
                'agents' => $agentNames,
            ]);
        }

        // Ready endpoint (no auth)
        if ($path === '/ready') {
            return $this->jsonResponse(200, ['status' => 'ready']);
        }

        // Root index: list registered agents (no auth)
        if ($path === '/' || $path === '') {
            return $this->handleRootIndex();
        }

        // Check static file routes (longest prefix match)
        $staticResult = $this->handleStaticFile($path);
        if ($staticResult !== null) {
            return $staticResult;
        }

        // Find matching agent by longest prefix
        $matchedRoute = $this->findMatchingRoute($path);

        if ($matchedRoute !== null) {
            $agent = $this->agents[$matchedRoute];
            return $agent->handleRequest($method, $path, $headers, $body);
        }

        return $this->jsonResponse(404, ['error' => 'Not Found']);
    }

    // ======================================================================
    //  Server Lifecycle
    // ======================================================================

    /**
     * Start the server (blocking).
     */
    public function run(): void
    {
        $this->serve();
    }

    /**
     * Dispatch the current PHP request (cli-server / php-fpm / mod_php) to
     * this server's {@see self::handleRequest()} and write the response.
     *
     * Mirrors {@see \SignalWire\SWML\Service::dispatchFromGlobals()}: the
     * plaintext `php -S` router re-invokes the entry script under the
     * cli-server SAPI, which rebuilds this AgentServer and calls run(); the
     * cli-server branch of serve() then routes through here so the multi-agent
     * routing (including the served /sip → routing-callback → 307 path) is
     * actually reachable over the socket, not just via handleRequest() in
     * process.
     */
    private function dispatchFromGlobals(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $method = is_string($method) ? $method : 'GET';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url(is_string($requestUri) ? $requestUri : '/', PHP_URL_PATH) ?: '/';

        // Reconstruct headers from $_SERVER (works in every SAPI).
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'HTTP_') && is_string($v)) {
                $name = ucwords(strtolower(str_replace('_', '-', substr($k, 5))), '-');
                $headers[$name] = $v;
            }
        }
        $contentType = $_SERVER['CONTENT_TYPE'] ?? null;
        if (is_string($contentType)) {
            $headers['Content-Type'] = $contentType;
        }
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        if (is_string($contentLength)) {
            $headers['Content-Length'] = $contentLength;
        }
        // Recover the Authorization header the cli-server SAPI strips for CGI
        // safety (same recovery as Service::dispatchFromGlobals).
        if (!isset($headers['Authorization'])) {
            $authVal = $_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? null;
            if (is_string($authVal) && $authVal !== '') {
                $headers['Authorization'] = $authVal;
            } elseif (is_string($_SERVER['PHP_AUTH_USER'] ?? null)) {
                $user = $_SERVER['PHP_AUTH_USER'];
                $pwRaw = $_SERVER['PHP_AUTH_PW'] ?? '';
                $pw = is_string($pwRaw) ? $pwRaw : '';
                $headers['Authorization'] = 'Basic ' . base64_encode($user . ':' . $pw);
            }
        }

        $body = file_get_contents('php://input');
        if ($body === false || $body === '') {
            $body = null;
        }

        [$status, $respHeaders, $respBody] = $this->handleRequest($method, $path, $headers, $body);

        if (!headers_sent()) {
            http_response_code($status);
            foreach ($respHeaders as $k => $v) {
                header("{$k}: {$v}", true);
            }
        }
        echo $respBody;
    }

    /**
     * Start the server (blocking).
     *
     * Mirrors Python's AgentServer SSL handling: when ``SWML_SSL_ENABLED`` is
     * truthy and ``SWML_SSL_CERT_PATH`` / ``SWML_SSL_KEY_PATH`` point at a
     * readable cert + key, the server is served over verified HTTPS via a
     * Workerman SSL worker. Otherwise it falls back to PHP's built-in
     * (plaintext HTTP) server.
     */
    public function serve(): void
    {
        // Under the cli-server SAPI we are already inside a `php -S` worker
        // that re-invoked the entry script (which rebuilt this server and
        // called run()). Dispatch the inbound request and emit the response
        // directly instead of re-spawning — mirrors Service::serve().
        if (PHP_SAPI === 'cli-server') {
            $this->dispatchFromGlobals();
            return;
        }

        $this->logger->info("AgentServer starting on {$this->host}:{$this->port}");

        foreach ($this->getAgents() as $route) {
            $agent = $this->agents[$route];
            $this->logger->info("  Agent '{$agent->getName()}' registered at {$route}");
        }

        [$certPath, $keyPath] = $this->resolveSslPaths();
        if ($certPath !== null && $keyPath !== null) {
            $this->serveTls($certPath, $keyPath);
            return;
        }

        $addr = "{$this->host}:{$this->port}";
        // The router IS the original entry script the user ran: when `php -S`
        // re-invokes it per request, the builder reconstructs the server and
        // calls run(), which this time takes the cli-server branch above and
        // dispatches through handleRequest(). A generated empty router would
        // never reach the registered agents (the previous stub behaviour).
        $entry = $this->resolveEntryScript();
        $cmd = sprintf(
            '%s -S %s %s',
            escapeshellcmd(PHP_BINARY),
            escapeshellarg($addr),
            escapeshellarg($entry),
        );
        passthru($cmd);
    }

    /**
     * Resolve the path of the original entry script that constructed this
     * server, so `php -S` can re-invoke it as the router. Mirrors
     * {@see \SignalWire\SWML\Service::resolveEntryScript()}.
     */
    private function resolveEntryScript(): string
    {
        $env = getenv('SWML_SERVICE_ENTRY');
        if (is_string($env) && $env !== '' && file_exists($env)) {
            return realpath($env) ?: $env;
        }
        $script = $_SERVER['SCRIPT_FILENAME'] ?? null;
        if (is_string($script) && $script !== '' && file_exists($script)) {
            return realpath($script) ?: $script;
        }
        $argv = $_SERVER['argv'] ?? null;
        $argv0 = is_array($argv) ? ($argv[0] ?? null) : null;
        if (is_string($argv0) && $argv0 !== '') {
            $abs = realpath($argv0);
            if ($abs !== false && file_exists($abs)) {
                return $abs;
            }
        }
        $sdkDir = realpath(__DIR__ . '/../..');
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;
            if (!is_string($file) || $file === '') {
                continue;
            }
            $abs = realpath($file);
            if ($abs === false) {
                continue;
            }
            if ($sdkDir !== false && str_starts_with($abs, $sdkDir)) {
                continue;
            }
            return $abs;
        }
        throw new \RuntimeException(
            'Could not locate the entry script for AgentServer::serve(). '
            . 'Set the SWML_SERVICE_ENTRY env var to the entry path or '
            . 'invoke the script via `php <your-server>.php`.'
        );
    }

    /**
     * Resolve the SSL cert/key paths from the environment, mirroring
     * Python's ``SWML_SSL_ENABLED`` / ``SWML_SSL_CERT_PATH`` /
     * ``SWML_SSL_KEY_PATH`` contract. Returns ``[cert, key]`` when SSL is
     * enabled AND both files exist; otherwise ``[null, null]`` (plaintext).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveSslPaths(): array
    {
        $enabled = strtolower((string) (getenv('SWML_SSL_ENABLED') ?: '')) ;
        if (!in_array($enabled, ['true', '1', 'yes'], true)) {
            return [null, null];
        }
        $cert = getenv('SWML_SSL_CERT_PATH') ?: '';
        $key  = getenv('SWML_SSL_KEY_PATH') ?: '';
        if ($cert === '' || !is_file($cert)) {
            $this->logger->warn("SSL cert not found: {$cert}");
            return [null, null];
        }
        if ($key === '' || !is_file($key)) {
            $this->logger->warn("SSL key not found: {$key}");
            return [null, null];
        }
        return [$cert, $key];
    }

    /**
     * Serve the registered agents over verified HTTPS using a Workerman SSL
     * worker (blocking — runs the Workerman event loop). PHP's built-in
     * ``php -S`` server speaks HTTP only, so TLS termination is delegated to
     * Workerman's ``transport => 'ssl'`` socket with ``local_cert`` /
     * ``local_pk``. Each inbound HTTP request is translated to the SDK's
     * existing {@see self::handleRequest()} and the resulting
     * ``[status, headers, body]`` is written back verbatim.
     */
    private function serveTls(string $certPath, string $keyPath): void
    {
        if (!class_exists(\Workerman\Worker::class)) {
            throw new \RuntimeException(
                'HTTPS serving requires workerman/workerman (composer require workerman/workerman)'
            );
        }

        $this->logger->info("Starting with SSL - cert: {$certPath}, key: {$keyPath}");

        $worker = new \Workerman\Worker(
            "http://{$this->host}:{$this->port}",
            ['ssl' => [
                'local_cert'  => $certPath,
                'local_pk'    => $keyPath,
                'verify_peer' => false, // server side: we present a cert, we don't require a client cert
            ]],
        );
        $worker->name = 'signalwire-agent-server';
        $worker->count = 1;
        $worker->transport = 'ssl';

        $server = $this;
        $worker->onMessage = static function (
            \Workerman\Connection\TcpConnection $connection,
            \Workerman\Protocols\Http\Request $request
        ) use ($server): void {
            $headers = [];
            $rawHeaders = $request->header();
            if (is_array($rawHeaders)) {
                foreach ($rawHeaders as $name => $value) {
                    if (!is_string($name)) {
                        continue;
                    }
                    if (is_array($value)) {
                        $headers[$name] = implode(', ', array_map(
                            static fn ($v): string => is_string($v) ? $v : '',
                            $value
                        ));
                    } elseif (is_string($value)) {
                        $headers[$name] = $value;
                    }
                }
            }
            $body = $request->rawBody();

            [$status, $respHeaders, $respBody] = $server->handleRequest(
                $request->method(),
                $request->path(),
                $headers,
                $body === '' ? null : $body,
            );

            $connection->send(new \Workerman\Protocols\Http\Response(
                $status,
                $respHeaders,
                $respBody,
            ));
        };

        \Workerman\Worker::runAll();
    }

    // ======================================================================
    //  Accessors
    // ======================================================================

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    // ======================================================================
    //  Private Helpers
    // ======================================================================

    /**
     * Handle the root index request, listing all registered agents.
     *
     * @return array{int, array<string, string>, string}
     */
    private function handleRootIndex(): array
    {
        $agentList = [];
        foreach ($this->getAgents() as $route) {
            $agent = $this->agents[$route];
            $agentList[] = [
                'name'  => $agent->getName(),
                'route' => $route,
            ];
        }

        return $this->jsonResponse(200, [
            'agents' => $agentList,
        ]);
    }

    /**
     * Attempt to serve a static file for the given path.
     *
     * @return array{int, array<string, string>, string}|null
     */
    private function handleStaticFile(string $path): ?array
    {
        // Sort by longest prefix first
        $routes = array_keys($this->staticRoutes);
        usort($routes, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($routes as $prefix) {
            $normalPrefix = $prefix === '/' ? '' : $prefix;

            // Check if path starts with this prefix
            if ($prefix !== '/' && $path !== $prefix && !str_starts_with($path, $normalPrefix . '/')) {
                continue;
            }
            // Don't serve root path as a static file
            if ($prefix === '/' && $path === '/') {
                continue;
            }

            $relPath = substr($path, strlen($normalPrefix));
            $relPath = ltrim($relPath, '/');

            // Path traversal protection: reject ".." components
            if (preg_match('#(?:^|/)\.\.(?:/|$)#', $relPath)) {
                return $this->forbiddenResponse();
            }

            $baseDir  = $this->staticRoutes[$prefix];
            $filePath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);

            // Resolve to absolute and verify it's within the base directory
            $absPath = realpath($filePath);
            if ($absPath === false) {
                // File doesn't exist, fall through
                continue;
            }

            if (!str_starts_with($absPath, $baseDir)) {
                return $this->forbiddenResponse();
            }

            if (is_file($absPath) && is_readable($absPath)) {
                // Determine MIME type
                $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
                $contentType = self::MIME_TYPES[$ext] ?? 'application/octet-stream';

                $content = file_get_contents($absPath);
                if ($content === false) {
                    return [
                        500,
                        array_merge(
                            ['Content-Type' => 'text/plain'],
                            $this->securityHeaders(),
                        ),
                        'Internal Server Error',
                    ];
                }

                return [
                    200,
                    array_merge(
                        [
                            'Content-Type'   => $contentType,
                            'Content-Length'  => (string) strlen($content),
                        ],
                        $this->securityHeaders(),
                    ),
                    $content,
                ];
            }
        }

        return null;
    }

    /**
     * Find the matching agent route for a request path (longest prefix match).
     */
    private function findMatchingRoute(string $path): ?string
    {
        $routes = array_keys($this->agents);
        usort($routes, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($routes as $route) {
            if ($route === '/') {
                return $route;
            }
            if ($path === $route || str_starts_with($path, $route . '/')) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Normalize a route: ensure leading slash, strip trailing slashes (unless root).
     */
    private function normalizeRoute(string $route): string
    {
        if (!str_starts_with($route, '/')) {
            $route = '/' . $route;
        }
        if ($route !== '/') {
            $route = rtrim($route, '/');
        }
        return $route;
    }

    /**
     * Normalize a request path: strip trailing slashes (unless root).
     */
    private function normalizePath(string $path): string
    {
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        return $path ?: '/';
    }

    /**
     * Build a 403 Forbidden response with security headers.
     *
     * @return array{int, array<string, string>, string}
     */
    private function forbiddenResponse(): array
    {
        return [
            403,
            array_merge(
                ['Content-Type' => 'text/plain'],
                $this->securityHeaders(),
            ),
            'Forbidden',
        ];
    }

    /**
     * Security headers applied to responses.
     *
     * @return array<string, string>
     */
    private function securityHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'DENY',
            'Cache-Control'          => 'no-store',
        ];
    }

    /**
     * Build a JSON response tuple.
     *
     * @return array{int, array<string, string>, string}
     */
    private function jsonResponse(int $status, mixed $data): array
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('json_encode failed');
        }
        return [
            $status,
            array_merge(
                ['Content-Type' => 'application/json'],
                $this->securityHeaders(),
            ),
            $body,
        ];
    }

}
