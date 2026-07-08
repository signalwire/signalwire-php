<?php

declare(strict_types=1);

namespace SignalWire\Core;

/**
 * JSON configuration loader with environment-variable interpolation.
 *
 * Mirrors Python's ``signalwire.core.config_loader.ConfigLoader`` (wire/semantic
 * contract) and TS's ``ConfigLoader`` (the canonical shape). Supports ``${VAR|default}``
 * substitution inside string values and dot-notation access into nested config.
 *
 * PHP idiom: a plain class under ``SignalWire\Core`` (PSR-4 file-per-class); the
 * ordered-search constructor accepts a single path or a list of paths and loads
 * the first that exists (matching Python's ordered ``config_paths`` search).
 * Mirrors signalwire/signalwire/core/config_loader.py
 */
class ConfigLoader
{
    /** Sentinel distinguishing "path not found" from a legitimate null value. */
    private const MISSING = "\0__config_missing__\0";

    /** @var array<string, mixed>|null */
    private ?array $config = null;
    private ?string $configFile = null;

    /** @var list<string> */
    private array $configPaths;

    /**
     * Initialise the loader.
     *
     * @param list<string>|null $configPaths Ordered list of config file paths to
     *                                        try; the first existing file is loaded.
     *                                        Defaults to the standard search paths.
     */
    public function __construct(?array $configPaths = null)
    {
        $this->configPaths = $configPaths ?? $this->getDefaultPaths();
        $this->loadConfig();
    }

    /**
     * Default configuration-file search paths (order matters).
     *
     * @return list<string>
     */
    private function getDefaultPaths(): array
    {
        $home = self::homeDir();
        return [
            'config.json',
            'agent_config.json',
            'swml_config.json',
            '.swml/config.json',
            $home . '/.swml/config.json',
            '/etc/swml/config.json',
        ];
    }

    /**
     * Load configuration from the first existing path in the search list.
     */
    private function loadConfig(): void
    {
        foreach ($this->configPaths as $path) {
            if (is_file($path)) {
                $raw = @file_get_contents($path);
                if ($raw === false) {
                    continue;
                }
                try {
                    /** @var mixed $decoded */
                    $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    $this->config = $decoded;
                    $this->configFile = $path;
                    break;
                }
            }
        }
    }

    /**
     * Whether a configuration file was successfully loaded.
     */
    public function hasConfig(): bool
    {
        return $this->config !== null;
    }

    /**
     * The path of the loaded config file, or null if none was loaded.
     */
    public function getConfigFile(): ?string
    {
        return $this->configFile;
    }

    /**
     * The raw (pre-substitution) configuration array.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config ?? [];
    }

    /**
     * Recursively substitute ``${VAR|default}`` environment references in a value.
     *
     * Strings that resolve to ``true``/``false`` coerce to bool; all-digit strings
     * coerce to int; ``\d+\.\d+`` strings coerce to float. Arrays/maps are walked.
     *
     * @param mixed $value    Value to process (string, array, or scalar).
     * @param int   $maxDepth Recursion guard.
     *
     * @return mixed The substituted value.
     *
     * @throws \InvalidArgumentException When the recursion depth is exceeded.
     */
    public function substituteVars(mixed $value, int $maxDepth = 10): mixed
    {
        if ($maxDepth <= 0) {
            throw new \InvalidArgumentException('Maximum variable substitution depth exceeded');
        }

        if (is_string($value)) {
            $result = preg_replace_callback(
                '/\$\{([^}|]+)(?:\|([^}]*))?\}/',
                static function (array $m): string {
                    $varName = $m[1];
                    $default = $m[2] ?? '';
                    $env = getenv($varName);
                    return $env !== false ? $env : $default;
                },
                $value
            );
            $result ??= $value;

            $lower = strtolower($result);
            if ($lower === 'true' || $lower === 'false') {
                return $lower === 'true';
            }
            if (preg_match('/^\d+$/', $result) === 1) {
                return (int) $result;
            }
            // Python: result.replace('.', '', 1).isdigit() -> float
            if (preg_match('/^\d+\.\d+$/', $result) === 1 || preg_match('/^\d+\.$/', $result) === 1) {
                return (float) $result;
            }
            return $result;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->substituteVars($v, $maxDepth - 1);
            }
            return $out;
        }

        return $value;
    }

    /**
     * Get a configuration value by dot-notation path (e.g. ``security.ssl_enabled``).
     *
     * The returned value has environment variables substituted.
     *
     * @param mixed $default Value returned when the path does not resolve.
     *
     * @return mixed The resolved (substituted) value, or $default.
     */
    public function get(string $keyPath, mixed $default = null): mixed
    {
        if ($this->config === null) {
            return $default;
        }

        $value = $this->navigate($this->config, explode('.', $keyPath));
        if ($value === self::MISSING) {
            return $default;
        }

        return $this->substituteVars($value);
    }

    /**
     * Walk a nested array along a key path, returning {@see MISSING} when the
     * path does not resolve.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $keys
     *
     * @return mixed The resolved value, or the MISSING sentinel.
     */
    private function navigate(array $data, array $keys): mixed
    {
        $last = count($keys) - 1;
        $current = $data;
        foreach ($keys as $i => $key) {
            if (!array_key_exists($key, $current)) {
                return self::MISSING;
            }
            /** @var mixed $value */
            $value = $current[$key];
            if ($i === $last) {
                return $value;
            }
            if (!is_array($value)) {
                return self::MISSING;
            }
            $current = $value;
        }
        // Empty key list: return the whole array.
        return $data;
    }

    /**
     * Get an entire top-level configuration section with variables substituted.
     *
     * @return array<string, mixed> The section, or an empty array if absent.
     */
    public function getSection(string $section): array
    {
        if ($this->config === null || !array_key_exists($section, $this->config)) {
            return [];
        }
        $result = $this->substituteVars($this->config[$section]);
        return is_array($result) ? $result : [];
    }

    /**
     * Merge the loaded config with environment variables matching a prefix.
     *
     * Config-file values win; matching env keys are stripped of the prefix,
     * lowercased, and split on ``_`` into a nested path (``SWML_FOO_BAR`` ->
     * ``['foo' => ['bar' => value]]``) only where the config doesn't already
     * define that path.
     *
     * @return array<string, mixed> The merged configuration.
     */
    public function mergeWithEnv(string $envPrefix = 'SWML_'): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->config !== null ? (array) $this->substituteVars($this->config) : [];

        foreach ($this->environ() as $key => $value) {
            if (str_starts_with($key, $envPrefix)) {
                $configKey = strtolower(substr($key, strlen($envPrefix)));
                if (!$this->hasNestedKey($result, $configKey)) {
                    $this->setNestedKey($result, $configKey, $value);
                }
            }
        }

        return $result;
    }

    /**
     * Static helper to find a config file for a service without loading it.
     *
     * @param list<string>|null $additionalPaths Extra paths to check first.
     *
     * @return string|null The first existing path, or null.
     */
    public static function findConfigFile(
        ?string $serviceName = null,
        ?array $additionalPaths = null
    ): ?string {
        $paths = [];

        if ($serviceName !== null) {
            $paths[] = "{$serviceName}_config.json";
            $paths[] = ".swml/{$serviceName}_config.json";
        }

        if ($additionalPaths !== null) {
            foreach ($additionalPaths as $p) {
                $paths[] = $p;
            }
        }

        $home = self::homeDir();
        $paths[] = 'config.json';
        $paths[] = 'agent_config.json';
        $paths[] = '.swml/config.json';
        $paths[] = $home . '/.swml/config.json';
        $paths[] = '/etc/swml/config.json';

        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hasNestedKey(array $data, string $keyPath): bool
    {
        $keys = explode('_', $keyPath);
        $last = count($keys) - 1;
        $current = $data;
        foreach ($keys as $i => $key) {
            if (!array_key_exists($key, $current)) {
                return false;
            }
            if ($i === $last) {
                return true;
            }
            /** @var mixed $value */
            $value = $current[$key];
            // An intermediate leaf (non-array) means the deeper path can't
            // exist — Python's _has_nested_key returns False here.
            if (!is_array($value)) {
                return false;
            }
            $current = $value;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function setNestedKey(array &$data, string $keyPath, mixed $value): void
    {
        $keys = explode('_', $keyPath);
        $last = array_pop($keys);
        $current = &$data;
        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            /** @var array<string, mixed> $ref */
            $ref = &$current[$key];
            $current = &$ref;
        }
        $current[$last] = $value;
    }

    /**
     * The current process environment as an associative array.
     *
     * @return array<string, string>
     */
    private function environ(): array
    {
        /** @var array<string, string> $env */
        $env = getenv();
        return $env;
    }

    /**
     * Resolve the current user's home directory (``~`` expansion).
     */
    private static function homeDir(): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return $home;
        }
        $userProfile = getenv('USERPROFILE');
        if (is_string($userProfile) && $userProfile !== '') {
            return $userProfile;
        }
        return '~';
    }
}
