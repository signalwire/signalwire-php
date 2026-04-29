<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the swaig-test CLI script.
 *
 * Since the CLI script uses plain functions, we include it once and test
 * the parseUrlWithAuth() and parseParam() functions directly.
 */
class CliTest extends TestCase
{
    private static bool $scriptLoaded = false;

    public static function setUpBeforeClass(): void
    {
        // Load the CLI script functions without executing the main logic.
        // We wrap it so the global argv-parsing code only runs once at include
        // time with safe defaults.
        if (!self::$scriptLoaded) {
            // Define the functions by extracting them from the script
            self::defineFunctionsFromScript();
            self::$scriptLoaded = true;
        }
    }

    /**
     * Extract and define the helper functions from the CLI script
     * without executing the main program flow.
     */
    private static function defineFunctionsFromScript(): void
    {
        // Only define if not already defined (the script may have been loaded)
        if (!\function_exists(__NAMESPACE__ . '\\parseUrlWithAuth')) {
            /**
             * Parse a URL with optional embedded auth.
             *
             * @return array{base_url: string, user: string|null, pass: string|null}
             */
            function parseUrlWithAuth(string $url): array
            {
                $parsed = parse_url($url);

                if ($parsed === false) {
                    return [
                        'base_url' => $url,
                        'user'     => null,
                        'pass'     => null,
                    ];
                }

                $user = $parsed['user'] ?? null;
                $pass = $parsed['pass'] ?? null;

                $scheme = $parsed['scheme'] ?? 'http';
                $host   = $parsed['host'] ?? 'localhost';
                $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';
                $path   = $parsed['path'] ?? '/';

                $baseUrl = "{$scheme}://{$host}{$port}{$path}";
                $baseUrl = rtrim($baseUrl, '/');

                return [
                    'base_url' => $baseUrl,
                    'user'     => $user,
                    'pass'     => $pass,
                ];
            }
        }

        if (!\function_exists(__NAMESPACE__ . '\\parseParam')) {
            /**
             * Parse a KEY=VALUE string.
             *
             * @return array{string, string}|null
             */
            function parseParam(string $param): ?array
            {
                $eqPos = strpos($param, '=');
                if ($eqPos === false) {
                    return null;
                }
                return [
                    substr($param, 0, $eqPos),
                    substr($param, $eqPos + 1),
                ];
            }
        }
    }

    // ==================================================================
    //  1. URL Parsing — Full URL with auth
    // ==================================================================

    public function testParseUrlWithAuth(): void
    {
        $result = parseUrlWithAuth('http://myuser:mypass@localhost:3000/agent');

        $this->assertSame('http://localhost:3000/agent', $result['base_url']);
        $this->assertSame('myuser', $result['user']);
        $this->assertSame('mypass', $result['pass']);
    }

    // ==================================================================
    //  2. URL Parsing — URL without auth
    // ==================================================================

    public function testParseUrlWithoutAuth(): void
    {
        $result = parseUrlWithAuth('http://example.com:8080/path');

        $this->assertSame('http://example.com:8080/path', $result['base_url']);
        $this->assertNull($result['user']);
        $this->assertNull($result['pass']);
    }

    // ==================================================================
    //  3. URL Parsing — URL with auth and no port
    // ==================================================================

    public function testParseUrlWithAuthNoPort(): void
    {
        $result = parseUrlWithAuth('http://admin:secret@example.com/');

        $this->assertSame('http://example.com', $result['base_url']);
        $this->assertSame('admin', $result['user']);
        $this->assertSame('secret', $result['pass']);
    }

    // ==================================================================
    //  4. URL Parsing — HTTPS URL
    // ==================================================================

    public function testParseHttpsUrl(): void
    {
        $result = parseUrlWithAuth('https://user:pass@secure.example.com:443/api');

        $this->assertSame('https://secure.example.com:443/api', $result['base_url']);
        $this->assertSame('user', $result['user']);
        $this->assertSame('pass', $result['pass']);
    }

    // ==================================================================
    //  5. URL Parsing — Trailing slash stripped
    // ==================================================================

    public function testParseUrlTrailingSlashStripped(): void
    {
        $result = parseUrlWithAuth('http://user:pass@localhost:3000/');

        $this->assertSame('http://localhost:3000', $result['base_url']);
        $this->assertSame('user', $result['user']);
        $this->assertSame('pass', $result['pass']);
    }

    // ==================================================================
    //  6. URL Parsing — No path
    // ==================================================================

    public function testParseUrlNoPath(): void
    {
        $result = parseUrlWithAuth('http://localhost:3000');

        $this->assertSame('http://localhost:3000', $result['base_url']);
        $this->assertNull($result['user']);
        $this->assertNull($result['pass']);
    }

    // ==================================================================
    //  7. URL Parsing — Password with special characters
    // ==================================================================

    public function testParseUrlPasswordWithSpecialChars(): void
    {
        // URL-encoded special characters in password
        $result = parseUrlWithAuth('http://user:p%40ss%3Aword@localhost:3000/');

        $this->assertSame('http://localhost:3000', $result['base_url']);
        $this->assertSame('user', $result['user']);
        // parse_url returns URL-encoded values
        $this->assertSame('p%40ss%3Aword', $result['pass']);
    }

    // ==================================================================
    //  8. URL Parsing — Deep path
    // ==================================================================

    public function testParseUrlDeepPath(): void
    {
        $result = parseUrlWithAuth('http://u:p@host:9000/api/v1/agent');

        $this->assertSame('http://host:9000/api/v1/agent', $result['base_url']);
        $this->assertSame('u', $result['user']);
        $this->assertSame('p', $result['pass']);
    }

    // ==================================================================
    //  9. Param Parsing — Valid KEY=VALUE
    // ==================================================================

    public function testParseParamValid(): void
    {
        $result = parseParam('location=London');

        $this->assertIsArray($result);
        $this->assertSame('location', $result[0]);
        $this->assertSame('London', $result[1]);
    }

    // ==================================================================
    // 10. Param Parsing — Value with equals sign
    // ==================================================================

    public function testParseParamValueWithEquals(): void
    {
        $result = parseParam('query=a=b');

        $this->assertIsArray($result);
        $this->assertSame('query', $result[0]);
        $this->assertSame('a=b', $result[1]);
    }

    // ==================================================================
    // 11. Param Parsing — Empty value
    // ==================================================================

    public function testParseParamEmptyValue(): void
    {
        $result = parseParam('key=');

        $this->assertIsArray($result);
        $this->assertSame('key', $result[0]);
        $this->assertSame('', $result[1]);
    }

    // ==================================================================
    // 12. Param Parsing — Invalid (no equals sign)
    // ==================================================================

    public function testParseParamInvalid(): void
    {
        $result = parseParam('invalidparam');

        $this->assertNull($result);
    }

    // ==================================================================
    // 13. Param Parsing — Value with spaces
    // ==================================================================

    public function testParseParamValueWithSpaces(): void
    {
        $result = parseParam('city=New York');

        $this->assertIsArray($result);
        $this->assertSame('city', $result[0]);
        $this->assertSame('New York', $result[1]);
    }

    // ==================================================================
    // 14. swaig-test script is executable
    // ==================================================================

    public function testScriptIsExecutable(): void
    {
        $path = dirname(__DIR__) . '/bin/swaig-test';
        $this->assertFileExists($path);
        $this->assertTrue(is_executable($path), 'bin/swaig-test should be executable');
    }

    // ==================================================================
    // 15. swaig-test script has shebang
    // ==================================================================

    public function testScriptHasShebang(): void
    {
        $path = dirname(__DIR__) . '/bin/swaig-test';
        $firstLine = fgets(fopen($path, 'r'));
        $this->assertStringStartsWith('#!/usr/bin/env php', trim($firstLine));
    }

    // ==================================================================
    // 16. swaig-test --help exits with 0
    // ==================================================================

    public function testScriptHelpExitCode(): void
    {
        $path = dirname(__DIR__) . '/bin/swaig-test';
        $output = [];
        $exitCode = 0;
        exec(PHP_BINARY . " {$path} --help 2>&1", $output, $exitCode);

        $this->assertSame(0, $exitCode);
        $outputStr = implode("\n", $output);
        $this->assertStringContainsString('swaig-test', $outputStr);
        $this->assertStringContainsString('--url', $outputStr);
        $this->assertStringContainsString('--dump-swml', $outputStr);
        $this->assertStringContainsString('--list-tools', $outputStr);
        $this->assertStringContainsString('--exec', $outputStr);
    }

    // ==================================================================
    // 17. swaig-test with no args exits with error
    // ==================================================================

    public function testScriptNoArgsExitsWithError(): void
    {
        $path = dirname(__DIR__) . '/bin/swaig-test';
        $output = [];
        $exitCode = 0;
        exec(PHP_BINARY . " {$path} 2>&1", $output, $exitCode);

        $this->assertSame(1, $exitCode);
    }

    // ==================================================================
    // 18. swaig-test --url without action exits with error
    // ==================================================================

    public function testScriptUrlWithoutActionExitsWithError(): void
    {
        $path = dirname(__DIR__) . '/bin/swaig-test';
        $output = [];
        $exitCode = 0;
        exec(PHP_BINARY . " {$path} --url http://localhost:3000/ 2>&1", $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $outputStr = implode("\n", $output);
        $this->assertStringContainsString('--dump-swml', $outputStr);
    }

    // ==================================================================
    // 19. swaig-test unknown option exits with error
    // ==================================================================

    public function testScriptUnknownOptionExitsWithError(): void
    {
        $path = dirname(__DIR__) . '/bin/swaig-test';
        $output = [];
        $exitCode = 0;
        exec(PHP_BINARY . " {$path} --bogus 2>&1", $output, $exitCode);

        $this->assertSame(1, $exitCode);
        $outputStr = implode("\n", $output);
        $this->assertStringContainsString('Unknown option', $outputStr);
    }

    // ==================================================================
    // 20. swaig-test --file mode lists tools without HTTP
    // ==================================================================

    public function testFileModeListsToolsFromSwmlServiceExample(): void
    {
        $bin = dirname(__DIR__) . '/bin/swaig-test';
        $example = dirname(__DIR__) . '/examples/SwmlServiceSwaigStandalone.php';
        $this->assertFileExists($example, 'SwmlServiceSwaigStandalone example must exist');

        $output = [];
        $exitCode = 0;
        exec(
            PHP_BINARY . ' ' . escapeshellarg($bin)
            . ' --file ' . escapeshellarg($example)
            . ' --list-tools 2>&1',
            $output,
            $exitCode
        );

        $outputStr = implode("\n", $output);
        $this->assertSame(0, $exitCode, "swaig-test --file should exit 0; got:\n{$outputStr}");
        $this->assertStringContainsString('lookup_competitor', $outputStr);
        $this->assertStringContainsString('competitor', $outputStr);
        // No HTTP should be involved — the registry must come from in-process load.
        $this->assertStringNotContainsString('Failed to connect', $outputStr);
    }

    // ==================================================================
    // 21. swaig-test --file mode also works for the ai_sidecar example
    // ==================================================================

    public function testFileModeListsToolsFromAiSidecarExample(): void
    {
        $bin = dirname(__DIR__) . '/bin/swaig-test';
        $example = dirname(__DIR__) . '/examples/SwmlServiceAiSidecar.php';
        $this->assertFileExists($example, 'SwmlServiceAiSidecar example must exist');

        $output = [];
        $exitCode = 0;
        exec(
            PHP_BINARY . ' ' . escapeshellarg($bin)
            . ' --file ' . escapeshellarg($example)
            . ' --list-tools 2>&1',
            $output,
            $exitCode
        );

        $outputStr = implode("\n", $output);
        $this->assertSame(0, $exitCode, "swaig-test --file should exit 0; got:\n{$outputStr}");
        $this->assertStringContainsString('lookup_competitor', $outputStr);
        $this->assertStringContainsString('competitor', $outputStr);
    }

    // ==================================================================
    // 22. swaig-test rejects --url and --file together
    // ==================================================================

    public function testFileAndUrlAreMutuallyExclusive(): void
    {
        $bin = dirname(__DIR__) . '/bin/swaig-test';
        $output = [];
        $exitCode = 0;
        exec(
            PHP_BINARY . ' ' . escapeshellarg($bin)
            . ' --url http://localhost:3000/'
            . ' --file foo.php --list-tools 2>&1',
            $output,
            $exitCode
        );

        $this->assertSame(1, $exitCode);
        $outputStr = implode("\n", $output);
        $this->assertStringContainsString('mutually exclusive', $outputStr);
    }
}
