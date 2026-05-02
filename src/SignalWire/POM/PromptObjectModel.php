<?php

declare(strict_types=1);

namespace SignalWire\POM;

/**
 * A structured data format for composing, organizing, and rendering prompt
 * instructions for large language models.
 *
 * Mirrors Python's ``signalwire.pom.pom.PromptObjectModel``. Provides a
 * tree-based representation of nested sections, supporting JSON / YAML
 * serialization and Markdown / XML rendering.
 *
 * YAML support: a small bundled emitter and parser implement the subset of
 * PyYAML's default block style needed for round-tripping POM documents.
 * Composer-installed yaml extensions are not required.
 */
class PromptObjectModel
{
    /** @var list<Section> */
    public array $sections = [];
    public bool $debug;

    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }

    // ---------------------------------------------------------------------
    // Static factories
    // ---------------------------------------------------------------------

    /**
     * Create a PromptObjectModel from a JSON string or a parsed array.
     *
     * @param string|array<int, mixed> $jsonData
     *
     * @throws \InvalidArgumentException on malformed JSON or invalid structure
     */
    public static function fromJson(string|array $jsonData): PromptObjectModel
    {
        if (is_string($jsonData)) {
            try {
                /** @var mixed $data */
                $data = json_decode($jsonData, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException('Invalid JSON: ' . $e->getMessage(), 0, $e);
            }
        } else {
            $data = $jsonData;
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException('JSON root must be an array of sections.');
        }
        return self::fromArray($data);
    }

    /**
     * Create a PromptObjectModel from a YAML string or a parsed array.
     *
     * @param string|array<int, mixed> $yamlData
     */
    public static function fromYaml(string|array $yamlData): PromptObjectModel
    {
        if (is_string($yamlData)) {
            $data = self::yamlDecode($yamlData);
        } else {
            $data = $yamlData;
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException('YAML root must be a list of sections.');
        }
        return self::fromArray($data);
    }

    /**
     * Build a PromptObjectModel from a list of section dicts.
     *
     * Validates structure (matches Python ``_from_dict``):
     *   - each entry must be a dict
     *   - title (if present) must be a string
     *   - bullets, subsections must be lists; numbered/numberedBullets must
     *     be bool
     *   - every section must have body OR bullets OR subsections
     *   - subsections must have a title
     *   - subsequent (non-first) top-level sections without ``title`` are
     *     auto-titled "Untitled Section" (Python parity)
     *
     * @param array<int, mixed> $data
     */
    private static function fromArray(array $data): PromptObjectModel
    {
        $pom = new PromptObjectModel();
        foreach ($data as $i => $sec) {
            if (!is_array($sec)) {
                throw new \InvalidArgumentException('Each section must be a dictionary.');
            }
            if ($i > 0 && !array_key_exists('title', $sec)) {
                $sec['title'] = 'Untitled Section';
            }
            $pom->sections[] = self::buildSection($sec, false);
        }
        return $pom;
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function buildSection(array $d, bool $isSubsection): Section
    {
        if (array_key_exists('title', $d) && $d['title'] !== null && !is_string($d['title'])) {
            throw new \InvalidArgumentException("'title' must be a string if present.");
        }
        if (array_key_exists('subsections', $d) && !is_array($d['subsections'])) {
            throw new \InvalidArgumentException("'subsections' must be a list if provided.");
        }
        if (array_key_exists('bullets', $d) && !is_array($d['bullets'])) {
            throw new \InvalidArgumentException("'bullets' must be a list if provided.");
        }
        if (array_key_exists('numbered', $d) && !is_bool($d['numbered'])) {
            throw new \InvalidArgumentException("'numbered' must be a boolean if provided.");
        }
        if (array_key_exists('numberedBullets', $d) && !is_bool($d['numberedBullets'])) {
            throw new \InvalidArgumentException("'numberedBullets' must be a boolean if provided.");
        }

        $hasBody = array_key_exists('body', $d) && !empty($d['body']);
        $hasBullets = array_key_exists('bullets', $d) && !empty($d['bullets']);
        $hasSubsections = array_key_exists('subsections', $d) && !empty($d['subsections']);
        if (!$hasBody && !$hasBullets && !$hasSubsections) {
            throw new \InvalidArgumentException(
                'All sections must have either a non-empty body, non-empty bullets, or subsections'
            );
        }

        if ($isSubsection && !array_key_exists('title', $d)) {
            throw new \InvalidArgumentException('All subsections must have a title');
        }

        $params = [
            'body' => $d['body'] ?? '',
            'bullets' => $d['bullets'] ?? [],
        ];
        if (array_key_exists('numbered', $d)) {
            $params['numbered'] = $d['numbered'];
        }
        if (array_key_exists('numberedBullets', $d)) {
            $params['numberedBullets'] = $d['numberedBullets'];
        }

        $section = new Section($d['title'] ?? null, $params);

        foreach ($d['subsections'] ?? [] as $sub) {
            if (!is_array($sub)) {
                throw new \InvalidArgumentException('Each subsection must be a dictionary.');
            }
            $section->subsections[] = self::buildSection($sub, true);
        }

        return $section;
    }

    // ---------------------------------------------------------------------
    // Mutators / accessors
    // ---------------------------------------------------------------------

    /**
     * Add a top-level section to the model.
     *
     * @param array{
     *   body?: string,
     *   bullets?: list<string>|string|null,
     *   numbered?: bool|null,
     *   numberedBullets?: bool
     * } $params
     *
     * @throws \InvalidArgumentException If $title is null and there is already
     *                                   at least one section
     */
    public function addSection(?string $title = null, array $params = []): Section
    {
        if ($title === null && count($this->sections) > 0) {
            throw new \InvalidArgumentException('Only the first section can have no title');
        }

        $bullets = $params['bullets'] ?? null;
        if (is_string($bullets)) {
            $bullets = [$bullets];
        }
        $params['bullets'] = $bullets ?? [];

        $section = new Section($title, $params);
        $this->sections[] = $section;
        return $section;
    }

    /**
     * Recursively search for a section by title.
     */
    public function findSection(string $title): ?Section
    {
        return $this->recurseFind($this->sections, $title);
    }

    /**
     * @param list<Section> $sections
     */
    private function recurseFind(array $sections, string $title): ?Section
    {
        foreach ($sections as $section) {
            if ($section->title === $title) {
                return $section;
            }
            $found = $this->recurseFind($section->subsections, $title);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // Serialization
    // ---------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (Section $s): array => $s->toArray(), $this->sections);
    }

    /**
     * Serialize the model as JSON. Matches Python ``json.dumps(.., indent=2)``.
     */
    public function toJson(): string
    {
        $data = $this->toArray();
        // Python json.dumps([], indent=2) returns "[]" (empty array).
        // PHP json_encode([], JSON_PRETTY_PRINT) returns "[]" — same.
        // For non-empty, PHP uses 4-space indent by default; force 2-space
        // by re-rendering manually.
        if (empty($data)) {
            return '[]';
        }
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('json_encode failed');
        }
        // Convert PHP's 4-space indent to Python's 2-space indent. PHP uses
        // exactly 4 spaces per level via JSON_PRETTY_PRINT; replace at line
        // starts. Each leading run of 4 spaces becomes 2 spaces.
        return self::reindent4to2($encoded);
    }

    private static function reindent4to2(string $s): string
    {
        $lines = explode("\n", $s);
        foreach ($lines as &$line) {
            $n = 0;
            $len = strlen($line);
            while ($n + 4 <= $len && substr($line, $n, 4) === '    ') {
                $n += 4;
            }
            if ($n > 0) {
                $depth = $n / 4;
                $line = str_repeat('  ', (int) $depth) . substr($line, $n);
            }
        }
        unset($line);
        return implode("\n", $lines);
    }

    /**
     * Serialize the model as YAML.  Matches PyYAML's default block style
     * with ``default_flow_style=False, sort_keys=False``.
     */
    public function toYaml(): string
    {
        $data = $this->toArray();
        if (empty($data)) {
            return "[]\n";
        }
        return self::yamlEncodeList($data, 0);
    }

    public function renderMarkdown(): string
    {
        $anySectionNumbered = false;
        foreach ($this->sections as $section) {
            if ($section->numbered === true) {
                $anySectionNumbered = true;
                break;
            }
        }

        $md = [];
        $sectionCounter = 0;
        foreach ($this->sections as $section) {
            if ($section->title !== null) {
                $sectionCounter += 1;
                if ($anySectionNumbered && $section->numbered !== false) {
                    $sectionNumber = [$sectionCounter];
                } else {
                    $sectionNumber = [];
                }
            } else {
                $sectionNumber = [];
            }
            $md[] = $section->renderMarkdown(2, $sectionNumber);
        }

        return implode("\n", $md);
    }

    public function renderXml(): string
    {
        $xml = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<prompt>',
        ];

        $anySectionNumbered = false;
        foreach ($this->sections as $section) {
            if ($section->numbered === true) {
                $anySectionNumbered = true;
                break;
            }
        }

        $sectionCounter = 0;
        foreach ($this->sections as $section) {
            if ($section->title !== null) {
                $sectionCounter += 1;
                if ($anySectionNumbered && $section->numbered !== false) {
                    $sectionNumber = [$sectionCounter];
                } else {
                    $sectionNumber = [];
                }
            } else {
                $sectionNumber = [];
            }
            $xml[] = $section->renderXml(1, $sectionNumber);
        }

        $xml[] = '</prompt>';
        return implode("\n", $xml);
    }

    /**
     * Add another PromptObjectModel as a subsection block under a target.
     *
     * @param string|Section $target Either a section title or a Section instance.
     *
     * @throws \InvalidArgumentException When the title cannot be resolved.
     */
    public function addPomAsSubsection(string|Section $target, PromptObjectModel $pomToAdd): void
    {
        if (is_string($target)) {
            $targetSection = $this->findSection($target);
            if ($targetSection === null) {
                throw new \InvalidArgumentException("No section with title '$target' found.");
            }
        } else {
            $targetSection = $target;
        }

        foreach ($pomToAdd->sections as $section) {
            $targetSection->subsections[] = $section;
        }
    }

    // ---------------------------------------------------------------------
    // YAML emit / parse — small bundled dialect compatible with PyYAML's
    // default block style (default_flow_style=False, sort_keys=False).
    // ---------------------------------------------------------------------

    /**
     * Emit a YAML list of maps at ``$dashIndent`` (number of spaces before
     * the leading "- ").  Each map's key columns sit at ``$dashIndent + 2``.
     *
     * @param array<int, array<string, mixed>> $list
     */
    private static function yamlEncodeList(array $list, int $dashIndent): string
    {
        $out = '';
        $pad = str_repeat(' ', $dashIndent);
        $keyIndent = $dashIndent + 2;
        foreach ($list as $item) {
            $isFirst = true;
            foreach ($item as $key => $val) {
                $prefix = $isFirst ? ($pad . '- ') : str_repeat(' ', $keyIndent);
                $out .= self::yamlEncodeKeyValue((string) $key, $val, $prefix, $keyIndent);
                $isFirst = false;
            }
        }
        return $out;
    }

    /**
     * Emit a single ``key: value`` pair with the supplied line prefix.  When
     * ``$val`` is a non-scalar collection, the value continues on subsequent
     * lines indented at ``$keyIndent`` (matching PyYAML's default style: the
     * nested list's dashes sit at the same column as the key).
     */
    private static function yamlEncodeKeyValue(string $key, mixed $val, string $prefix, int $keyIndent): string
    {
        if (is_array($val)) {
            if (empty($val)) {
                return $prefix . $key . ": []\n";
            }
            if (array_is_list($val)) {
                $out = $prefix . $key . ":\n";
                if (self::isScalarList($val)) {
                    // Bullets list — dash at same column as the parent key.
                    foreach ($val as $item) {
                        $out .= str_repeat(' ', $keyIndent) . '- ' . self::yamlScalar($item) . "\n";
                    }
                } else {
                    // Subsections list — dash at same column as the parent key.
                    $out .= self::yamlEncodeList($val, $keyIndent);
                }
                return $out;
            }
            // Associative array == YAML map; nested keys indent by 2.
            $out = $prefix . $key . ":\n";
            foreach ($val as $k => $v) {
                $childPrefix = str_repeat(' ', $keyIndent + 2);
                $out .= self::yamlEncodeKeyValue((string) $k, $v, $childPrefix, $keyIndent + 2);
            }
            return $out;
        }

        return $prefix . $key . ': ' . self::yamlScalar($val) . "\n";
    }

    /**
     * @param array<int, mixed> $list
     */
    private static function isScalarList(array $list): bool
    {
        foreach ($list as $item) {
            if (is_array($item)) {
                return false;
            }
        }
        return true;
    }

    private static function yamlScalar(mixed $val): string
    {
        if ($val === null) {
            return 'null';
        }
        if (is_bool($val)) {
            return $val ? 'true' : 'false';
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }
        $s = (string) $val;
        // Quote if contains characters that would confuse the parser, OR
        // looks like a YAML special token (true/false/null/numeric/etc).
        if (self::needsYamlQuote($s)) {
            return self::yamlSingleQuote($s);
        }
        return $s;
    }

    private static function needsYamlQuote(string $s): bool
    {
        if ($s === '') {
            return true;
        }
        // Reserved words / look-alikes
        $lower = strtolower($s);
        if (in_array($lower, ['true', 'false', 'null', 'yes', 'no', '~', 'on', 'off'], true)) {
            return true;
        }
        if (is_numeric($s)) {
            return true;
        }
        // Special characters at start
        $first = $s[0];
        if (in_array($first, ['!', '&', '*', '?', '|', '>', '\'', '"', '%', '@', '`', '#', '-', '['], true)) {
            return true;
        }
        // Contains : followed by space, # preceded by space, leading/trailing
        // whitespace, newlines, tabs, or non-printable characters
        if (preg_match('/[:\n\t]/', $s)) {
            return true;
        }
        if (str_contains($s, ': ') || str_contains($s, ' #')) {
            return true;
        }
        if ($s !== trim($s)) {
            return true;
        }
        return false;
    }

    private static function yamlSingleQuote(string $s): string
    {
        // PyYAML uses single-quotes for plain text containing specials, and
        // doubles inner single-quotes.
        return "'" . str_replace("'", "''", $s) . "'";
    }

    /**
     * Decode a YAML string into a native PHP value (subset).
     *
     * Supports: a top-level list of maps with scalar / nested-list / nested-map
     * values, with PyYAML's default block style. Sufficient for POM
     * round-tripping.
     *
     * @return array<int|string, mixed>
     */
    private static function yamlDecode(string $yaml): array
    {
        $yaml = str_replace(["\r\n", "\r"], "\n", $yaml);
        $rawLines = explode("\n", $yaml);
        $lines = [];
        foreach ($rawLines as $ln) {
            // Strip trailing CR / spaces (but preserve indent)
            $stripped = rtrim($ln);
            if ($stripped === '' || ltrim($stripped)[0] === '#') {
                continue;
            }
            $lines[] = $stripped;
        }
        if (empty($lines)) {
            return [];
        }

        $pos = 0;
        // Top level can be either a flow-style "[]" or a block list.
        $first = ltrim($lines[0]);
        if ($first === '[]') {
            return [];
        }
        return self::parseList($lines, $pos, 0);
    }

    /**
     * Parse a block list at the given indent. Items may be either scalars
     * (``- foo``) or maps (``- key: value`` continued by indented keys).
     *
     * @param list<string> $lines
     * @return list<mixed>
     */
    private static function parseList(array $lines, int &$pos, int $indent): array
    {
        $items = [];
        while ($pos < count($lines)) {
            $line = $lines[$pos];
            $lineIndent = self::leadingSpaces($line);
            if ($lineIndent < $indent) {
                break;
            }
            $stripped = substr($line, $lineIndent);
            if (!str_starts_with($stripped, '- ') && $stripped !== '-') {
                break;
            }
            $rest = $stripped === '-' ? '' : substr($stripped, 2);
            if ($rest === '') {
                // Bare "-" introduces a block map at lineIndent + 2.
                $pos += 1;
                $items[] = self::parseBlockMap($lines, $pos, $lineIndent + 2);
                continue;
            }

            // Determine: is this a scalar list item ("- foo") or a map item
            // ("- key: value")? We use splitKeyValue to discriminate.
            $kv = self::splitKeyValue($rest);
            if ($kv === null) {
                // Scalar.
                $items[] = self::parseScalar($rest);
                $pos += 1;
                continue;
            }

            // Map item — first key on the dash line, subsequent keys indented
            // at lineIndent + 2.
            [$key, $val] = $kv;
            $pos += 1;
            $itemMap = [];
            if ($val === '') {
                $itemMap[$key] = self::parseBlockValue($lines, $pos, $lineIndent + 2);
            } else {
                $itemMap[$key] = self::parseScalar($val);
            }
            while ($pos < count($lines)) {
                $nextLine = $lines[$pos];
                $nextIndent = self::leadingSpaces($nextLine);
                if ($nextIndent !== $lineIndent + 2) {
                    break;
                }
                $nextStripped = substr($nextLine, $nextIndent);
                if (str_starts_with($nextStripped, '- ') || $nextStripped === '-') {
                    break;
                }
                $kv2 = self::splitKeyValue($nextStripped);
                if ($kv2 === null) {
                    break;
                }
                [$k2, $v2] = $kv2;
                $pos += 1;
                if ($v2 === '') {
                    $itemMap[$k2] = self::parseBlockValue($lines, $pos, $lineIndent + 2);
                } else {
                    $itemMap[$k2] = self::parseScalar($v2);
                }
            }
            $items[] = $itemMap;
        }
        return $items;
    }

    /**
     * Parse a value following "key:" — could be a scalar (already consumed),
     * a block list at indent, a block map at indent, or empty.
     *
     * @param list<string> $lines
     */
    private static function parseBlockValue(array $lines, int &$pos, int $parentMapIndent): mixed
    {
        if ($pos >= count($lines)) {
            return null;
        }
        $line = $lines[$pos];
        $lineIndent = self::leadingSpaces($line);
        $stripped = substr($line, $lineIndent);
        if (str_starts_with($stripped, '- ') || $stripped === '-') {
            // Block list. PyYAML default emits the dash at the same indent as
            // the parent key (parentMapIndent), but some parsers indent it.
            // Accept any indent >= parentMapIndent.
            return self::parseList($lines, $pos, $lineIndent);
        }
        // Block map at indent > parentMapIndent
        if ($lineIndent > $parentMapIndent) {
            return self::parseBlockMap($lines, $pos, $lineIndent);
        }
        return null;
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>
     */
    private static function parseBlockMap(array $lines, int &$pos, int $indent): array
    {
        $map = [];
        while ($pos < count($lines)) {
            $line = $lines[$pos];
            $lineIndent = self::leadingSpaces($line);
            if ($lineIndent < $indent) {
                break;
            }
            if ($lineIndent !== $indent) {
                break;
            }
            $stripped = substr($line, $lineIndent);
            if (str_starts_with($stripped, '- ') || $stripped === '-') {
                break;
            }
            $kv = self::splitKeyValue($stripped);
            if ($kv === null) {
                break;
            }
            [$k, $v] = $kv;
            $pos += 1;
            if ($v === '') {
                $map[$k] = self::parseBlockValue($lines, $pos, $indent);
            } else {
                $map[$k] = self::parseScalar($v);
            }
        }
        return $map;
    }

    private static function leadingSpaces(string $line): int
    {
        $n = 0;
        $len = strlen($line);
        while ($n < $len && $line[$n] === ' ') {
            $n += 1;
        }
        return $n;
    }

    /**
     * @return array{string, string}|null
     */
    private static function splitKeyValue(string $line): ?array
    {
        // Find first ':' that's followed by space or end-of-line.
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            if ($line[$i] === ':') {
                $next = $i + 1 < $len ? $line[$i + 1] : "\n";
                if ($next === ' ' || $next === "\n" || $i + 1 === $len) {
                    $key = self::stripQuotes(rtrim(substr($line, 0, $i)));
                    $val = trim(substr($line, $i + 1));
                    return [$key, $val];
                }
            }
        }
        return null;
    }

    private static function stripQuotes(string $s): string
    {
        if (strlen($s) >= 2) {
            $first = $s[0];
            $last = $s[strlen($s) - 1];
            if ($first === '"' && $last === '"') {
                return self::unescapeDoubleQuoted(substr($s, 1, -1));
            }
            if ($first === "'" && $last === "'") {
                return str_replace("''", "'", substr($s, 1, -1));
            }
        }
        return $s;
    }

    private static function unescapeDoubleQuoted(string $s): string
    {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $n = $s[++$i];
                $out .= match ($n) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '\\' => '\\',
                    '"' => '"',
                    default => $n,
                };
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    private static function parseScalar(string $val): mixed
    {
        $val = trim($val);
        if ($val === '' || $val === '~' || strtolower($val) === 'null') {
            return null;
        }
        $lower = strtolower($val);
        if ($lower === 'true' || $lower === 'yes') {
            return true;
        }
        if ($lower === 'false' || $lower === 'no') {
            return false;
        }
        if (str_starts_with($val, '"') || str_starts_with($val, "'")) {
            return self::stripQuotes($val);
        }
        if (preg_match('/^-?\d+$/', $val)) {
            return (int) $val;
        }
        if (preg_match('/^-?\d+\.\d+$/', $val)) {
            return (float) $val;
        }
        return $val;
    }
}
