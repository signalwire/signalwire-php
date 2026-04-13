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
        $this->port     = $port ?? (int) ($_ENV['PORT'] ?? getenv('PORT') ?: 3000);
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
     * Enable SIP-based routing.
     */
    public function setupSipRouting(): self
    {
        $this->sipRoutingEnabled = true;
        return $this;
    }

    /**
     * Map a SIP username to a route.
     */
    public function registerSipUsername(string $username, string $route): self
    {
        $route = $this->normalizeRoute($route);
        $this->sipUsernameMapping[$username] = $route;
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
     * @throws \RuntimeException If the directory does not exist.
     */
    public function serveStatic(string $directory, string $urlPrefix): self
    {
        $realDir = realpath($directory);
        if ($realDir === false || !is_dir($realDir)) {
            throw new \RuntimeException("Static directory '{$directory}' does not exist");
        }

        $urlPrefix = $this->normalizeRoute($urlPrefix);
        $this->staticRoutes[$urlPrefix] = $realDir;

        return $this;
    }

    // ======================================================================
    //  Request Handling
    // ======================================================================

    /**
     * Handle an HTTP request and return [status, headers, body].
     *
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
     * Start the server using PHP's built-in server (blocking).
     */
    public function serve(): void
    {
        $this->logger->info("AgentServer starting on {$this->host}:{$this->port}");

        foreach ($this->getAgents() as $route) {
            $agent = $this->agents[$route];
            $this->logger->info("  Agent '{$agent->getName()}' registered at {$route}");
        }

        $addr = "{$this->host}:{$this->port}";
        $router = $this->createRouterScript();
        $cmd = sprintf('php -S %s %s', escapeshellarg($addr), escapeshellarg($router));
        passthru($cmd);
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
        usort($routes, fn(string $a, string $b): int => strlen($b) <=> strlen($a));

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
        usort($routes, fn(string $a, string $b): int => strlen($b) <=> strlen($a));

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
        return [
            $status,
            array_merge(
                ['Content-Type' => 'application/json'],
                $this->securityHeaders(),
            ),
            $body,
        ];
    }

    /**
     * Create a temporary PHP router script for the built-in server.
     */
    private function createRouterScript(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sw_server_');
        file_put_contents($tmp, <<<'PHP'
        <?php
        // Auto-generated router script for AgentServer
        // Delegates all requests to the AgentServer
        PHP);

        return $tmp;
    }
}
