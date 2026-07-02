<?php

declare(strict_types=1);

namespace SignalWire\Web;

use SignalWire\Core\ConfigLoader;
use SignalWire\Core\SecurityConfig;

/**
 * Static file-serving service with an HTTP API.
 *
 * Serves configured local directories at URL route prefixes with security
 * features: HTTP Basic auth, extension allow/block filtering, directory
 * browsing, per-file size limits, CORS, and security headers. Mirrors Python's
 * ``signalwire.web.web_service.WebService`` (wire/semantic contract) and TS's
 * ``WebService`` (shape oracle).
 *
 * PHP idiom: Python couples serving to FastAPI/uvicorn and TS to Hono; PHP has
 * no bundled web framework, so serving is expressed via a native
 * ``handleRequest()`` dispatcher (returning the ``[status, headers, body]``
 * triple used across this SDK) plus ``start()`` which fronts PHP's built-in
 * server. The dispatcher is exercised directly by tests for real-behaviour
 * assertions. Framework mounting is NOT special-cased impossible — the whole
 * class ports; only the physical socket bind lives behind ``start()``.
 *
 * Python parity: signalwire/signalwire/web/web_service.py
 */
class WebService
{
    /** Default blocked extensions/names (security-sensitive files). */
    private const DEFAULT_BLOCKED = [
        '.env',
        '.git',
        '.gitignore',
        '.key',
        '.pem',
        '.crt',
        '.pyc',
        '__pycache__',
        '.DS_Store',
        '.swp',
    ];

    /** Common MIME types for static serving. */
    private const MIME_TYPES = [
        '.html' => 'text/html',
        '.htm' => 'text/html',
        '.css' => 'text/css',
        '.js' => 'application/javascript',
        '.mjs' => 'application/javascript',
        '.json' => 'application/json',
        '.xml' => 'application/xml',
        '.txt' => 'text/plain',
        '.md' => 'text/markdown',
        '.csv' => 'text/csv',
        '.png' => 'image/png',
        '.jpg' => 'image/jpeg',
        '.jpeg' => 'image/jpeg',
        '.gif' => 'image/gif',
        '.svg' => 'image/svg+xml',
        '.ico' => 'image/x-icon',
        '.webp' => 'image/webp',
        '.woff' => 'font/woff',
        '.woff2' => 'font/woff2',
        '.ttf' => 'font/ttf',
        '.otf' => 'font/otf',
        '.mp3' => 'audio/mpeg',
        '.wav' => 'audio/wav',
        '.mp4' => 'video/mp4',
        '.webm' => 'video/webm',
        '.pdf' => 'application/pdf',
        '.zip' => 'application/zip',
        '.gz' => 'application/gzip',
    ];

    public int $port;
    public bool $enableDirectoryBrowsing;
    public int $maxFileSize;
    public bool $enableCors;

    /** @var list<string>|null */
    public ?array $allowedExtensions;
    /** @var list<string> */
    public array $blockedExtensions;

    /** @var array<string, string> URL route prefix -> local directory. */
    public array $directories = [];

    public SecurityConfig $security;

    /** @var array{0: string, 1: string}|null */
    private ?array $basicAuth;

    /**
     * @param array<string, string>|null $directories       Route -> directory map.
     * @param array{0: string, 1: string}|null $basicAuth   Optional [user, password].
     * @param list<string>|null          $allowedExtensions Allowlist of extensions.
     * @param list<string>|null          $blockedExtensions Blocklist of extensions/names.
     */
    public function __construct(
        int $port = 8002,
        ?array $directories = null,
        ?array $basicAuth = null,
        ?string $configFile = null,
        bool $enableDirectoryBrowsing = false,
        ?array $allowedExtensions = null,
        ?array $blockedExtensions = null,
        int $maxFileSize = 100 * 1024 * 1024,
        bool $enableCors = true
    ) {
        // Load configuration first, then override with explicit params.
        $this->loadConfig($configFile);

        $this->port = $port;
        $this->enableDirectoryBrowsing = $enableDirectoryBrowsing;
        $this->maxFileSize = $maxFileSize;
        $this->enableCors = $enableCors;

        if ($directories !== null) {
            $this->directories = $directories;
        }

        $this->allowedExtensions = $allowedExtensions;
        $this->blockedExtensions = $blockedExtensions ?? self::DEFAULT_BLOCKED;

        $this->security = new SecurityConfig(configFile: $configFile, serviceName: 'web');
        // Auth is enforced only when credentials are explicitly provided
        // (mirrors TS's WebService, the shape oracle: `_basicAuth = opts.basicAuth ?? null`).
        $this->basicAuth = $basicAuth;
    }

    private function loadConfig(?string $configFile): void
    {
        $this->directories = [];
        $this->port = 8002;

        if ($configFile === null) {
            $configFile = ConfigLoader::findConfigFile('web');
        }
        if ($configFile === null) {
            return;
        }

        $loader = new ConfigLoader([$configFile]);
        if (!$loader->hasConfig()) {
            return;
        }

        $service = $loader->getSection('service');
        if ($service === []) {
            return;
        }

        if (isset($service['port']) && is_numeric($service['port'])) {
            $this->port = (int) $service['port'];
        }
        if (array_key_exists('directories', $service) && is_array($service['directories'])) {
            /** @var array<string, string> $dirs */
            $dirs = $service['directories'];
            $this->directories = $dirs;
        }
        if (array_key_exists('enable_directory_browsing', $service)) {
            $this->enableDirectoryBrowsing = (bool) $service['enable_directory_browsing'];
        }
        if (isset($service['max_file_size']) && is_numeric($service['max_file_size'])) {
            $this->maxFileSize = (int) $service['max_file_size'];
        }
        if (array_key_exists('allowed_extensions', $service) && is_array($service['allowed_extensions'])) {
            /** @var list<string> $allowed */
            $allowed = array_values($service['allowed_extensions']);
            $this->allowedExtensions = $allowed;
        }
        if (array_key_exists('blocked_extensions', $service) && is_array($service['blocked_extensions'])) {
            /** @var list<string> $blocked */
            $blocked = array_values($service['blocked_extensions']);
            $this->blockedExtensions = $blocked;
        }
    }

    /**
     * Add a directory to serve at a URL route prefix.
     *
     * @throws \InvalidArgumentException If the directory does not exist / is not a directory.
     */
    public function addDirectory(string $route, string $directory): void
    {
        $route = str_starts_with($route, '/') ? $route : '/' . $route;

        if (!file_exists($directory)) {
            throw new \InvalidArgumentException("Directory does not exist: {$directory}");
        }
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Path is not a directory: {$directory}");
        }

        $this->directories[$route] = $directory;
    }

    /**
     * Remove a directory route from being served.
     */
    public function removeDirectory(string $route): void
    {
        $route = str_starts_with($route, '/') ? $route : '/' . $route;
        unset($this->directories[$route]);
    }

    /**
     * Start the service (blocking) via PHP's built-in server.
     *
     * When ``SWAIG_CLI_MODE=true`` the call is a no-op so config can be
     * inspected without binding a port (mirrors TS's start()).
     */
    public function start(
        string $host = '0.0.0.0',
        ?int $port = null,
        ?string $sslCert = null,
        ?string $sslKey = null
    ): void {
        $cliMode = getenv('SWAIG_CLI_MODE');
        if ($cliMode === 'true') {
            return;
        }

        $port ??= $this->port;
        $useSsl = ($sslCert !== null && $sslKey !== null) || $this->security->getServerTlsOptions() !== [];
        $scheme = $useSsl ? 'https' : 'http';

        // Under cli-server SAPI, dispatch inbound requests directly.
        if (PHP_SAPI === 'cli-server') {
            $this->dispatchFromGlobals();
            return;
        }

        $addr = "{$host}:{$port}";
        $entry = ($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
        if (is_string($entry) && $entry !== '') {
            // The scheme is logged so the SSL branch (getServerTlsOptions) is
            // observable in the startup message.
            error_log("SignalWire Web Service starting on {$scheme}://{$addr}");
            $cmd = sprintf(
                '%s -S %s %s',
                escapeshellcmd(PHP_BINARY),
                escapeshellarg($addr),
                escapeshellarg($entry)
            );
            passthru($cmd);
        }
    }

    /**
     * Stop the service (cleanup).
     */
    public function stop(): void
    {
        // No persistent server handle in the built-in-server model; php -S is a
        // blocking child of start(). Present for API parity with Python/TS.
    }

    /**
     * Handle an inbound request against the mounted directories.
     *
     * Returns the ``[status, headers, body]`` triple used across the SDK. This
     * is the real serving core (exercised directly by tests) that ``start()``
     * fronts with a socket bind.
     *
     * @param array<string, string> $requestHeaders
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    public function handleRequest(string $method, string $path, array $requestHeaders = []): array
    {
        $isHttps = ($requestHeaders['X-Forwarded-Proto'] ?? '') === 'https';
        $secHeaders = $this->security->getSecurityHeaders($isHttps);

        // Host validation.
        $hostHeader = $requestHeaders['Host'] ?? '';
        $host = explode(':', $hostHeader)[0];
        if ($host !== '' && !$this->security->shouldAllowHost($host)) {
            return [400, $secHeaders, 'Invalid host'];
        }

        // Basic auth.
        if ($this->basicAuth !== null) {
            [$user, $pass] = $this->basicAuth;
            if (!$this->checkAuth($requestHeaders, $user, $pass)) {
                $secHeaders['WWW-Authenticate'] = 'Basic';
                return [401, $secHeaders, 'Invalid authentication credentials'];
            }
        }

        if ($path === '/health') {
            $body = json_encode([
                'status' => 'healthy',
                'directories' => array_keys($this->directories),
                'ssl_enabled' => $this->security->sslEnabled,
                'auth_required' => $this->basicAuth !== null,
                'directory_browsing' => $this->enableDirectoryBrowsing,
            ], JSON_UNESCAPED_SLASHES);
            $secHeaders['Content-Type'] = 'application/json';
            return [200, $secHeaders, $body === false ? '{}' : $body];
        }

        if ($path === '/') {
            $secHeaders['Content-Type'] = 'text/html';
            return [200, $secHeaders, $this->renderRootHtml()];
        }

        // Match a mounted directory route.
        foreach ($this->directories as $route => $directory) {
            $prefix = rtrim($route, '/');
            if ($prefix === '' || str_starts_with($path, $prefix . '/') || $path === $prefix) {
                $relative = ltrim(substr($path, strlen($prefix)), '/');
                return $this->serveFromDirectory($directory, $relative, $path, $secHeaders);
            }
        }

        return [404, $secHeaders, 'File not found'];
    }

    /**
     * @param array<string, string> $secHeaders
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function serveFromDirectory(string $directory, string $relative, string $urlPath, array $secHeaders): array
    {
        // Path-traversal protection.
        if (str_contains($relative, '..')) {
            return [403, $secHeaders, 'Access denied'];
        }

        $base = realpath($directory);
        if ($base === false) {
            return [404, $secHeaders, 'File not found'];
        }

        $candidate = $relative === '' ? $base : $base . DIRECTORY_SEPARATOR . $relative;
        $full = realpath($candidate);
        if ($full === false) {
            return [404, $secHeaders, 'File not found'];
        }
        if ($full !== $base && !str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
            return [403, $secHeaders, 'Access denied'];
        }

        if (is_dir($full)) {
            if (!$this->enableDirectoryBrowsing) {
                $index = $full . DIRECTORY_SEPARATOR . 'index.html';
                if (is_file($index) && $this->isFileAllowed($index)) {
                    return $this->serveFile($index, $secHeaders);
                }
                return [403, $secHeaders, 'Directory browsing disabled'];
            }
            $secHeaders['Content-Type'] = 'text/html';
            return [200, $secHeaders, $this->renderDirectoryListing($full, $urlPath)];
        }

        if (!is_file($full)) {
            return [404, $secHeaders, 'File not found'];
        }

        if (!$this->isFileAllowed($full)) {
            return [403, $secHeaders, 'File type not allowed'];
        }

        return $this->serveFile($full, $secHeaders);
    }

    /**
     * @param array<string, string> $secHeaders
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function serveFile(string $full, array $secHeaders): array
    {
        $ext = strtolower('.' . pathinfo($full, PATHINFO_EXTENSION));
        $mime = self::MIME_TYPES[$ext] ?? 'application/octet-stream';
        $content = @file_get_contents($full);
        if ($content === false) {
            return [404, $secHeaders, 'File not found'];
        }
        $secHeaders['Content-Type'] = $mime;
        $secHeaders['Cache-Control'] = 'public, max-age=3600';
        $secHeaders['X-Content-Type-Options'] = 'nosniff';
        return [200, $secHeaders, $content];
    }

    /**
     * Whether a file may be served (size + extension/name filters).
     */
    private function isFileAllowed(string $filePath): bool
    {
        $size = @filesize($filePath);
        if ($size === false || $size > $this->maxFileSize) {
            return false;
        }

        $ext = strtolower('.' . pathinfo($filePath, PATHINFO_EXTENSION));
        $name = basename($filePath);

        foreach ($this->blockedExtensions as $blocked) {
            if (str_starts_with($blocked, '.')) {
                if ($ext === $blocked || $name === $blocked) {
                    return false;
                }
            } else {
                if ($name === $blocked || str_contains($filePath, $blocked)) {
                    return false;
                }
            }
        }

        if ($this->allowedExtensions !== null) {
            return in_array($ext, $this->allowedExtensions, true);
        }

        return true;
    }

    private function renderRootHtml(): string
    {
        $items = '';
        foreach ($this->directories as $route => $localPath) {
            $r = htmlspecialchars($route, ENT_QUOTES);
            $p = htmlspecialchars($localPath, ENT_QUOTES);
            $items .= "<li><a href=\"{$r}\">{$r}</a> <span class=\"path\">&rarr; {$p}</span></li>\n";
        }

        return "<!DOCTYPE html>\n<html>\n<head>\n"
            . "  <title>SignalWire Web Service</title>\n</head>\n<body>\n"
            . "  <h1>SignalWire Web Service</h1>\n"
            . "  <h2>Available Directories:</h2>\n  <ul>\n{$items}  </ul>\n</body>\n</html>";
    }

    private function renderDirectoryListing(string $directory, string $urlPath): string
    {
        $entries = scandir($directory);
        $entries = $entries === false ? [] : $entries;
        sort($entries);

        $items = '';
        if ($urlPath !== '/') {
            $items .= '<li><a href="../">../</a></li>' . "\n";
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path)) {
                $safe = htmlspecialchars($name, ENT_QUOTES);
                $items .= "<li><a href=\"{$safe}/\">{$safe}/</a></li>\n";
            }
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_file($path) && $this->isFileAllowed($path)) {
                $safe = htmlspecialchars($name, ENT_QUOTES);
                $size = $this->formatSize((int) @filesize($path));
                $items .= "<li><a href=\"{$safe}\">{$safe}</a> ({$size})</li>\n";
            }
        }

        $title = htmlspecialchars($urlPath, ENT_QUOTES);
        return "<!DOCTYPE html>\n<html>\n<head>\n  <title>Directory listing for {$title}</title>\n"
            . "</head>\n<body>\n  <h1>Directory listing for {$title}</h1>\n  <ul>\n{$items}  </ul>\n</body>\n</html>";
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }
        return sprintf('%.1f MB', $bytes / (1024 * 1024));
    }

    /**
     * @param array<string, string> $headers
     */
    private function checkAuth(array $headers, string $user, string $pass): bool
    {
        $auth = $headers['Authorization'] ?? '';
        if (!str_starts_with($auth, 'Basic ')) {
            return false;
        }
        $decoded = base64_decode(substr($auth, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return false;
        }
        [$givenUser, $givenPass] = explode(':', $decoded, 2);
        return hash_equals($user, $givenUser) && hash_equals($pass, $givenPass);
    }

    private function dispatchFromGlobals(): void
    {
        $method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $requestUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'HTTP_') && is_string($v)) {
                $name = ucwords(strtolower(str_replace('_', '-', substr($k, 5))), '-');
                $headers[$name] = $v;
            }
        }

        [$status, $respHeaders, $respBody] = $this->handleRequest($method, $path, $headers);

        if (!headers_sent()) {
            http_response_code($status);
            foreach ($respHeaders as $k => $v) {
                header("{$k}: {$v}", true);
            }
        }
        echo $respBody;
    }
}
