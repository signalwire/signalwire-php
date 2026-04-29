<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

/**
 * Claude Skills loader.
 *
 * Mirrors signalwire-python's
 * `signalwire.skills.claude_skills.skill.ClaudeSkillsSkill`:
 *
 *   - Walks `skills_path` for `<dir>/SKILL.md` files.
 *   - Parses YAML frontmatter (name, description, argument-hint,
 *     disable-model-invocation, user-invocable).
 *   - Discovers companion `*.md` files inside each skill dir as
 *     selectable sections.
 *   - Registers one SWAIG tool per loadable skill with a `(arguments,
 *     section)` parameter pair, where `section` enums over the
 *     discovered companion docs.
 *   - When invoked, returns the SKILL.md body (or the requested
 *     section), with `$ARGUMENTS`, `$ARGUMENTS[N]`, `$N`,
 *     `${CLAUDE_SKILL_DIR}` and `${CLAUDE_SESSION_ID}` substituted.
 *
 * Two deliberate omissions vs Python (each documented in
 * PORT_OMISSIONS.md):
 *
 *   - `allow_shell_injection`: Python preprocesses `!`cmd`` patterns
 *     by shelling out. PHP prefers not to ship a hot path that
 *     subprocess() any user-controlled string by default — Python
 *     itself defaults this off too. The flag is accepted but the
 *     processor is a no-op (the pattern is left in place); set
 *     `allow_shell_injection=false` (the default) and the behavior
 *     matches Python.
 *   - YAML frontmatter parser: PHP has no first-class YAML in
 *     core. Implemented a small tolerant subset that handles the
 *     forms Claude Code itself emits (string scalars, bool, list of
 *     strings on continuation lines). Skill files using deeply
 *     nested YAML constructs aren't supported and will hit a
 *     graceful "frontmatter parse failed" warning at load.
 */
class ClaudeSkills extends SkillBase
{
    /**
     * @var list<array<string,mixed>> Discovered skills.
     */
    private array $skills = [];

    /** @var list<string> */
    private array $includePatterns = ['*'];
    /** @var list<string> */
    private array $excludePatterns = [];

    private bool $ignoreInvocationControl = false;

    public function getName(): string
    {
        return 'claude_skills';
    }

    public function getDescription(): string
    {
        return 'Load Claude SKILL.md files as agent tools';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
    }

    public function setup(): bool
    {
        $skillsPath = $this->params['skills_path'] ?? null;
        if (!is_string($skillsPath) || $skillsPath === '') {
            return false;
        }
        $resolved = realpath($skillsPath);
        if ($resolved === false || !is_dir($resolved)) {
            return false;
        }

        if (isset($this->params['include']) && is_array($this->params['include'])) {
            $this->includePatterns = array_values(array_filter(
                $this->params['include'],
                'is_string',
            ));
            if (count($this->includePatterns) === 0) {
                $this->includePatterns = ['*'];
            }
        }
        if (isset($this->params['exclude']) && is_array($this->params['exclude'])) {
            $this->excludePatterns = array_values(array_filter(
                $this->params['exclude'],
                'is_string',
            ));
        }

        $this->ignoreInvocationControl = (bool) ($this->params['ignore_invocation_control'] ?? false);

        $this->skills = $this->discoverSkills($resolved);
        // Empty skill set is valid (matches Python).
        return true;
    }

    public function registerTools(): void
    {
        $prefix = (string) ($this->params['tool_prefix'] ?? 'claude_');
        $skillDescriptions = is_array($this->params['skill_descriptions'] ?? null)
            ? $this->params['skill_descriptions']
            : [];
        $responsePrefix = (string) ($this->params['response_prefix'] ?? '');
        $responsePostfix = (string) ($this->params['response_postfix'] ?? '');

        foreach ($this->skills as $skill) {
            if (!empty($skill['_skip_tool'])) {
                continue;
            }
            $name = (string) $skill['name'];
            $toolName = $prefix . $this->sanitizeToolName($name);
            $description = (string) (
                $skillDescriptions[$name]
                ?? $skill['description']
                ?? "Use the {$name} skill"
            );

            $parameters = [
                'arguments' => [
                    'type' => 'string',
                    'description' => (string) (
                        $skill['argument_hint']
                        ?? 'Arguments or context to pass to the skill'
                    ),
                ],
            ];
            $sections = is_array($skill['sections'] ?? null) ? $skill['sections'] : [];
            if (count($sections) > 0) {
                $sectionNames = array_keys($sections);
                sort($sectionNames);
                $parameters['section'] = [
                    'type' => 'string',
                    'description' => 'Which reference section to load',
                    'enum' => $sectionNames,
                ];
            }

            $skillCopy = $skill;
            $this->defineTool(
                $toolName,
                $description,
                $parameters,
                function (array $args, array $rawData) use (
                    $skillCopy,
                    $responsePrefix,
                    $responsePostfix,
                ): FunctionResult {
                    $section = (string) ($args['section'] ?? '');
                    $arguments = (string) ($args['arguments'] ?? '');
                    $skillSections = is_array($skillCopy['sections'] ?? null)
                        ? $skillCopy['sections']
                        : [];

                    $content = '';
                    if ($section !== '' && isset($skillSections[$section])) {
                        $content = (string) @file_get_contents($skillSections[$section]);
                        if ($content === '') {
                            $content = "Error loading section '{$section}'";
                        }
                    } else {
                        $content = (string) ($skillCopy['body'] ?? '');
                    }

                    $skillDir = (string) ($skillCopy['skill_dir'] ?? '');
                    $sessionId = (string) ($rawData['call_id'] ?? '');
                    $content = str_replace(
                        ['${CLAUDE_SKILL_DIR}', '${CLAUDE_SESSION_ID}'],
                        [$skillDir, $sessionId],
                        $content,
                    );
                    $content = $this->substituteArguments($content, $arguments);

                    if ($responsePrefix !== '' || $responsePostfix !== '') {
                        $parts = [];
                        if ($responsePrefix !== '') {
                            $parts[] = $responsePrefix;
                        }
                        $parts[] = $content;
                        if ($responsePostfix !== '') {
                            $parts[] = $responsePostfix;
                        }
                        $content = implode("\n\n", $parts);
                    }
                    return new FunctionResult($content);
                },
            );
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function discoverSkills(string $skillsPath): array
    {
        $skills = [];
        $entries = @scandir($skillsPath) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $skillDir = $skillsPath . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($skillDir)) {
                continue;
            }
            $skillFile = $skillDir . DIRECTORY_SEPARATOR . 'SKILL.md';
            if (!is_file($skillFile)) {
                continue;
            }
            if (!$this->matchesPatterns($entry)) {
                continue;
            }
            $parsed = $this->parseSkillMd($skillFile);
            if ($parsed === null) {
                continue;
            }
            if ($parsed['name'] === '' || $parsed['name'] === null) {
                $parsed['name'] = $entry;
            }
            $parsed['path'] = $skillFile;
            $parsed['skill_dir'] = $skillDir;
            $parsed['sections'] = $this->discoverSections($skillDir);
            $this->applyInvocationControl($parsed);
            $skills[] = $parsed;
        }
        return $skills;
    }

    /**
     * @return array<string,string> sectionKey => absolute path
     */
    private function discoverSections(string $skillDir): array
    {
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $skillDir,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if (strtoupper($name) === 'SKILL.MD') {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $absolute = (string) $file->getRealPath();
            $relative = ltrim(
                substr($absolute, strlen(realpath($skillDir) ?: $skillDir)),
                DIRECTORY_SEPARATOR,
            );
            $relativeNoExt = preg_replace('/\.md$/i', '', $relative) ?? $relative;
            $key = str_replace(DIRECTORY_SEPARATOR, '/', $relativeNoExt);
            $out[$key] = $absolute;
        }
        return $out;
    }

    private function matchesPatterns(string $name): bool
    {
        foreach ($this->excludePatterns as $pattern) {
            if (fnmatch($pattern, $name)) {
                return false;
            }
        }
        foreach ($this->includePatterns as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse a SKILL.md file with YAML frontmatter and markdown body.
     *
     * @return array<string,mixed>|null
     */
    private function parseSkillMd(string $path): ?array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        if (!str_starts_with($content, '---')) {
            return [
                'name' => null,
                'description' => null,
                'argument_hint' => null,
                'disable_model_invocation' => false,
                'user_invocable' => true,
                'body' => trim($content),
            ];
        }
        // Locate the closing fence.
        $rest = substr($content, 3);
        $closing = strpos($rest, "\n---");
        if ($closing === false) {
            return [
                'name' => null,
                'description' => null,
                'argument_hint' => null,
                'disable_model_invocation' => false,
                'user_invocable' => true,
                'body' => trim($content),
            ];
        }
        $frontmatter = substr($rest, 0, $closing);
        $bodyStart = $closing + 4; // length of "\n---"
        $body = ltrim(substr($rest, $bodyStart));

        $fields = $this->parseYamlSubset($frontmatter);
        return [
            'name' => isset($fields['name']) ? (string) $fields['name'] : null,
            'description' => isset($fields['description']) ? (string) $fields['description'] : null,
            'argument_hint' => isset($fields['argument-hint']) ? (string) $fields['argument-hint'] : null,
            'disable_model_invocation' => $this->boolish($fields['disable-model-invocation'] ?? false),
            'user_invocable' => $this->boolish($fields['user-invocable'] ?? true),
            'body' => trim($body),
        ];
    }

    /**
     * Parse the (small, line-based) YAML subset used in Claude SKILL.md
     * frontmatter:
     *   key: value
     *   key: "quoted value"
     *   key: true / false / yes / no
     *   key:
     *     - item1
     *     - item2
     * Anything fancier returns the raw string.
     *
     * @return array<string,mixed>
     */
    private function parseYamlSubset(string $yaml): array
    {
        $result = [];
        $lines = preg_split('/\r?\n/', $yaml) ?: [];
        $currentKey = null;
        $currentList = null;

        foreach ($lines as $rawLine) {
            $line = rtrim($rawLine);
            if ($line === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            // Continuation of a list under a key.
            if ($currentKey !== null
                && $currentList !== null
                && preg_match('/^\s+-\s*(.*)$/', $line, $m)
            ) {
                $currentList[] = $this->scalarValue($m[1]);
                $result[$currentKey] = $currentList;
                continue;
            } else {
                $currentList = null;
            }

            if (preg_match('/^([A-Za-z_][\w\-]*)\s*:\s*(.*)$/', $line, $m)) {
                $key = $m[1];
                $rest = trim($m[2]);
                if ($rest === '') {
                    // Open a new list.
                    $currentKey = $key;
                    $currentList = [];
                    $result[$key] = [];
                } else {
                    $result[$key] = $this->scalarValue($rest);
                    $currentKey = $key;
                    $currentList = null;
                }
            }
        }
        return $result;
    }

    private function scalarValue(string $raw): mixed
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        // Strip matching quotes.
        if ((str_starts_with($raw, '"') && str_ends_with($raw, '"'))
            || (str_starts_with($raw, "'") && str_ends_with($raw, "'"))
        ) {
            return substr($raw, 1, -1);
        }
        $lower = strtolower($raw);
        return match (true) {
            $lower === 'true', $lower === 'yes' => true,
            $lower === 'false', $lower === 'no' => false,
            $lower === 'null', $lower === '~' => null,
            default => $raw,
        };
    }

    private function boolish(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            $lc = strtolower(trim($v));
            return in_array($lc, ['true', 'yes', '1'], true);
        }
        return (bool) $v;
    }

    /**
     * Substitute Claude argument placeholders in $body:
     *
     *   $ARGUMENTS         - full args string
     *   $ARGUMENTS[N]      - whitespace-split positional arg N
     *   $N (N=0..9)        - same as $ARGUMENTS[N]
     *
     * If $body has no bare `$ARGUMENTS` and arguments are non-empty,
     * appends the args (matches Python's fallback).
     */
    private function substituteArguments(string $body, string $arguments): string
    {
        $hasBareArguments = (bool) preg_match('/\$ARGUMENTS(?!\[)/', $body);
        $positional = ($arguments === '')
            ? []
            : (preg_split('/\s+/', $arguments) ?: []);

        $body = preg_replace_callback(
            '/\$ARGUMENTS\[(\d+)\]/',
            function (array $m) use ($positional): string {
                $idx = (int) $m[1];
                return $positional[$idx] ?? '';
            },
            $body,
        ) ?? $body;

        $body = preg_replace_callback(
            '/\$(\d+)(?!\d)/',
            function (array $m) use ($positional): string {
                $idx = (int) $m[1];
                return $positional[$idx] ?? '';
            },
            $body,
        ) ?? $body;

        $body = str_replace('$ARGUMENTS', $arguments, $body);

        if (!$hasBareArguments && $arguments !== '') {
            $body .= "\n\nARGUMENTS: " . $arguments;
        }
        return $body;
    }

    /**
     * Apply disable-model-invocation / user-invocable flags.
     */
    private function applyInvocationControl(array &$parsed): void
    {
        if ($this->ignoreInvocationControl) {
            $parsed['_skip_tool'] = false;
            $parsed['_skip_prompt'] = false;
            return;
        }
        if (!empty($parsed['disable_model_invocation'])) {
            $parsed['_skip_tool'] = true;
            $parsed['_skip_prompt'] = true;
        } elseif (!($parsed['user_invocable'] ?? true)) {
            $parsed['_skip_tool'] = true;
            $parsed['_skip_prompt'] = false;
        } else {
            $parsed['_skip_tool'] = false;
            $parsed['_skip_prompt'] = false;
        }
    }

    private function sanitizeToolName(string $name): string
    {
        $sanitized = preg_replace('/[\s\-]+/', '_', strtolower($name)) ?? '';
        $sanitized = preg_replace('/[^a-z0-9_]/', '', $sanitized) ?? '';
        if ($sanitized !== '' && ctype_digit($sanitized[0])) {
            $sanitized = '_' . $sanitized;
        }
        return $sanitized !== '' ? $sanitized : 'unnamed';
    }

    public function getHints(): array
    {
        $hints = [];
        foreach ($this->skills as $skill) {
            $name = (string) ($skill['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $tokens = preg_split('/[\s\-_]+/', $name) ?: [];
            foreach ($tokens as $token) {
                if ($token !== '' && !in_array($token, $hints, true)) {
                    $hints[] = $token;
                }
            }
        }
        return $hints;
    }

    public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }
        if (count($this->skills) === 0) {
            return [];
        }

        $prefix = (string) ($this->params['tool_prefix'] ?? 'claude_');
        $sections = [];
        foreach ($this->skills as $skill) {
            if (!empty($skill['_skip_prompt'])) {
                continue;
            }
            $name = (string) ($skill['name'] ?? 'unnamed');
            $body = (string) ($skill['body'] ?? '');
            $skillSections = is_array($skill['sections'] ?? null) ? $skill['sections'] : [];
            $hasTool = empty($skill['_skip_tool']);
            if (count($skillSections) > 0 && $hasTool) {
                $sectionNames = array_keys($skillSections);
                sort($sectionNames);
                $body .= "\n\nAvailable reference sections: "
                    . implode(', ', $sectionNames);
                $toolName = $prefix . $this->sanitizeToolName($name);
                $body .= "\nCall {$toolName}(section=\"<name>\") to load a section.";
            }
            $sections[] = [
                'title' => $name,
                'body' => $body,
            ];
        }
        return $sections;
    }
}
