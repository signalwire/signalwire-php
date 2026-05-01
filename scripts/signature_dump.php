<?php
/**
 * signature_dump.php — load every public class under SignalWire\\ via the
 * composer autoloader and dump its public method signatures as JSON.
 *
 * Output shape mirrors the .NET / Java raw dumps so the Python wrapper
 * (enumerate_signatures.py) can stay symmetric across ports.
 *
 * Usage: php scripts/signature_dump.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$root = realpath(__DIR__ . '/../src/SignalWire');
$classes = [];
$sourceFiles = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($rii as $f) {
    if ($f->isDir()) continue;
    if ($f->getExtension() !== 'php') continue;
    $sourceFiles[] = $f->getPathname();
    $relative = substr($f->getPathname(), strlen($root) + 1);
    if (str_ends_with($relative, '.php')) {
        $relative = substr($relative, 0, -4);
    }
    $fqcn = 'SignalWire\\' . str_replace('/', '\\', $relative);
    $classes[] = $fqcn;
}
sort($classes);
sort($sourceFiles);

// Force-load every source file so namespace-level free functions are
// registered (they only exist after the file is required, not via PSR-4).
foreach ($sourceFiles as $sf) {
    require_once $sf;
}

$out = ['types' => []];
foreach ($classes as $fqcn) {
    if (!class_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn)) {
        continue;
    }
    try {
        $r = new ReflectionClass($fqcn);
    } catch (Throwable $e) {
        fwrite(STDERR, "skipping {$fqcn}: {$e->getMessage()}\n");
        continue;
    }
    if ($r->isInternal()) continue;

    $kind = $r->isInterface() ? 'interface'
          : ($r->isTrait() ? 'trait'
          : ($r->isEnum() ? 'enum'
          : 'class'));

    $methods = [];

    // Constructor
    $ctor = $r->getConstructor();
    if ($ctor !== null && $ctor->isPublic()) {
        $methods[] = methodEntry($ctor, true);
    }

    // Public methods (declared on this class only — not inherited)
    foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->getDeclaringClass()->getName() !== $r->getName()) continue;
        if ($m->isConstructor()) continue;          // already emitted above
        if ($m->isDestructor()) continue;
        $methods[] = methodEntry($m, false);
    }

    // Public properties → emit as zero-arg "method" so the canonical
    // inventory aligns with Python @property idiom.
    $properties = [];
    foreach ($r->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
        if ($p->getDeclaringClass()->getName() !== $r->getName()) continue;
        $properties[] = [
            'name' => $p->getName(),
            'type' => typeString($p->getType()),
            'is_static' => $p->isStatic(),
        ];
    }

    $out['types'][] = [
        'namespace' => $r->getNamespaceName(),
        'name' => $r->getShortName(),
        'kind' => $kind,
        'methods' => $methods,
        'properties' => $properties,
    ];
}

// Free functions declared inside SignalWire\* namespaces.
$out['functions'] = [];
$declared = get_defined_functions();
foreach ($declared['user'] as $fn) {
    if (!str_starts_with($fn, 'signalwire\\')) {
        // PHP normalises namespace separators in get_defined_functions()
        // output to lowercase; check both cases.
        if (!str_starts_with(strtolower($fn), 'signalwire\\')) {
            continue;
        }
    }
    try {
        $rf = new ReflectionFunction($fn);
    } catch (Throwable $e) {
        continue;
    }
    $params = [];
    foreach ($rf->getParameters() as $p) {
        $hasDefault = $p->isDefaultValueAvailable();
        $default = null;
        if ($hasDefault) {
            try {
                $default = $p->getDefaultValue();
            } catch (Throwable $e) {
                $default = null;
            }
        }
        $params[] = [
            'name' => $p->getName(),
            'type' => typeString($p->getType()),
            'has_default' => $hasDefault,
            'default' => normaliseDefault($default),
            'is_variadic' => $p->isVariadic(),
            'is_optional' => $p->isOptional(),
            'allows_null' => $p->getType() !== null && $p->getType()->allowsNull(),
        ];
    }
    $out['functions'][] = [
        'namespace' => $rf->getNamespaceName(),
        'name' => $rf->getShortName(),
        'parameters' => $params,
        'return_type' => typeString($rf->getReturnType()),
        'return_allows_null' => $rf->getReturnType() !== null && $rf->getReturnType()->allowsNull(),
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

function methodEntry(ReflectionMethod $m, bool $isCtor): array {
    $declaringClass = $m->getDeclaringClass()->getName();
    $params = [];
    foreach ($m->getParameters() as $p) {
        $hasDefault = $p->isDefaultValueAvailable();
        $default = null;
        if ($hasDefault) {
            try {
                $default = $p->getDefaultValue();
            } catch (Throwable $e) {
                $default = null;
            }
        }
        $params[] = [
            'name' => $p->getName(),
            'type' => typeString($p->getType(), $declaringClass),
            'has_default' => $hasDefault,
            'default' => normaliseDefault($default),
            'is_variadic' => $p->isVariadic(),
            'is_optional' => $p->isOptional(),
            'allows_null' => $p->getType() !== null && $p->getType()->allowsNull(),
        ];
    }

    return [
        'name' => $m->getName(),
        'is_constructor' => $isCtor,
        'is_static' => $m->isStatic(),
        'parameters' => $params,
        'return_type' => typeString($m->getReturnType(), $declaringClass),
        'return_allows_null' => $m->getReturnType() !== null && $m->getReturnType()->allowsNull(),
    ];
}

function typeString(?ReflectionType $t, ?string $declaringClass = null): string {
    if ($t === null) return 'mixed';
    if ($t instanceof ReflectionUnionType) {
        $parts = array_map(fn($x) => typeString($x, $declaringClass), $t->getTypes());
        return implode('|', $parts);
    }
    if ($t instanceof ReflectionIntersectionType) {
        $parts = array_map(fn($x) => typeString($x, $declaringClass), $t->getTypes());
        return implode('&', $parts);
    }
    if ($t instanceof ReflectionNamedType) {
        return $t->getName();
    }
    return (string) $t;
}

function normaliseDefault($v) {
    if ($v === null) return null;
    if (is_scalar($v)) return $v;
    if (is_array($v)) return empty($v) ? [] : json_encode($v);
    return null;
}
