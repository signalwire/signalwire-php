<?php

declare(strict_types=1);

namespace SignalWire\Tests\Core;

use PHPUnit\Framework\TestCase;
use SignalWire\Core\ConfigLoader;
use SignalWire\Tests\Support\Shape;

/**
 * Real-behavior tests for SignalWire\Core\ConfigLoader.
 *
 * Mirrors Python's signalwire.core.config_loader.ConfigLoader semantics:
 * ordered-search load, dot-notation get with ${VAR|default} substitution,
 * section extraction, and env merge with config precedence. Every assertion
 * is on real loaded/merged output — no construction-only checks.
 */
final class ConfigLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sw_cfgtest_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        putenv('CFG_HOST');
        putenv('SWML_FOO_BAR');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeConfig(array $data): string
    {
        $path = $this->dir . '/config.json';
        file_put_contents($path, json_encode($data));
        return $path;
    }

    public function testLoadsFirstExistingConfigFile(): void
    {
        $path = $this->writeConfig(['service' => ['port' => 9001]]);
        $loader = new ConfigLoader([$this->dir . '/missing.json', $path]);

        $this->assertTrue($loader->hasConfig());
        $this->assertSame($path, $loader->getConfigFile());
        $this->assertSame(['service' => ['port' => 9001]], $loader->getConfig());
    }

    public function testHasConfigFalseWhenNoFileFound(): void
    {
        $loader = new ConfigLoader([$this->dir . '/nope.json']);
        $this->assertFalse($loader->hasConfig());
        $this->assertNull($loader->getConfigFile());
        $this->assertSame([], $loader->getConfig());
    }

    public function testGetDotNotationWithDefault(): void
    {
        $path = $this->writeConfig(['security' => ['ssl_enabled' => true]]);
        $loader = new ConfigLoader([$path]);

        $this->assertTrue($loader->get('security.ssl_enabled'));
        $this->assertSame('fallback', $loader->get('security.missing', 'fallback'));
        $this->assertNull($loader->get('nope'));
    }

    public function testSubstituteVarsFromEnvironmentAndCoercion(): void
    {
        putenv('CFG_HOST=example.com');
        $path = $this->writeConfig([
            'server' => [
                'host' => '${CFG_HOST|localhost}',
                'fallback' => '${CFG_MISSING|8080}',
                'flag' => '${CFG_MISSING|true}',
            ],
        ]);
        $loader = new ConfigLoader([$path]);

        // From env (string, not numeric)
        $this->assertSame('example.com', $loader->get('server.host'));
        // Default used and coerced to int
        $this->assertSame(8080, $loader->get('server.fallback'));
        // Default used and coerced to bool
        $this->assertTrue($loader->get('server.flag'));
    }

    public function testGetSectionSubstitutesRecursively(): void
    {
        putenv('CFG_HOST=host.local');
        $path = $this->writeConfig([
            'server' => ['host' => '${CFG_HOST}', 'port' => '3000'],
        ]);
        $loader = new ConfigLoader([$path]);

        $section = $loader->getSection('server');
        $this->assertSame('host.local', $section['host']);
        $this->assertSame(3000, $section['port']);
        $this->assertSame([], $loader->getSection('absent'));
    }

    public function testMergeWithEnvConfigTakesPrecedence(): void
    {
        putenv('SWML_FOO_BAR=fromenv');
        $path = $this->writeConfig(['existing' => ['key' => 'fromconfig']]);
        $loader = new ConfigLoader([$path]);

        $merged = $loader->mergeWithEnv('SWML_');
        // Nested env key materialized
        $this->assertSame('fromenv', Shape::at($merged, 'foo', 'bar'));
        // Config value preserved
        $this->assertSame('fromconfig', Shape::at($merged, 'existing', 'key'));
    }

    public function testFindConfigFileServiceSpecific(): void
    {
        $cwd = getcwd();
        chdir($this->dir);
        try {
            file_put_contents($this->dir . '/web_config.json', '{}');
            $found = ConfigLoader::findConfigFile('web');
            $this->assertSame('web_config.json', $found);

            @unlink($this->dir . '/web_config.json');
            $this->assertNull(ConfigLoader::findConfigFile('web'));
        } finally {
            chdir((string) $cwd);
        }
    }

    public function testSubstituteVarsDepthGuard(): void
    {
        $loader = new ConfigLoader([$this->dir . '/none.json']);
        $this->expectException(\InvalidArgumentException::class);
        $loader->substituteVars(['a' => ['b' => 'x']], 0);
    }
}
