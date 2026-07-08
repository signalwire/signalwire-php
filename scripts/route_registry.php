<?php

/**
 * route_registry.php — enumerate the REST routes the PHP SDK IMPLEMENTS.
 *
 * This is "Set B" for the cross-port SPEC-PARITY gate: the routes the live
 * RestClient actually dispatches, captured from the REAL code path — not parsed
 * from source (an AST scraper would have to re-implement the CrudResource /
 * BaseResource base-path machinery and would drift) and not read from the test
 * journal (which only sees routes that happen to be tested, the exact blind spot
 * the gate closes).
 *
 * How: construct RestClient, then swap its private HttpClient for a recording
 * subclass that overrides get/post/put/patch/delete to capture (verb, path) and
 * return [] instead of doing network I/O. Every route — CRUD-base, Calling
 * command, FabricTokens.createSubscriberToken, etc. — funnels through one of
 * those five verb methods. We then walk every namespace accessor on the client,
 * recurse into every sub-resource accessor, and invoke every route method (one
 * that returns array) with sentinel arguments. The path-param sentinel "{id}"
 * is one URL segment (no slash) so the captured path is already a template
 * comparable to the spec's path_template.
 *
 * Classification by reflection (structural, not a per-method skip list):
 *   - method returning an SDK class (not HttpClient), 0 required params
 *       → NAMESPACE/SUB-RESOURCE accessor: recurse into it.
 *   - method returning `array`
 *       → ROUTE method: invoke with sentinels, capture the HTTP call(s).
 *   - method returning HttpClient / string / bool / int / void / self
 *       → infra getter (getHttp/getBasePath/getProjectId/...): not a route,
 *         not a sub-resource — excluded structurally.
 *
 * A route method that cannot be invoked, or that issues no HTTP request, is NOT
 * silently dropped — a dropped route would turn a real divergence into a false
 * "PHP matches the spec" pass. Such methods are hard errors (recorded in
 * `errors`, non-zero exit) unless listed in REGISTRY_SKIP with a reason.
 *
 * Output: JSON {"routes":[{"method","path_template","via"}],"skipped":[...],
 * "errors":[...]} on stdout. Exit 1 if any uninvokable / no-HTTP, un-skip-listed
 * route method (Set B incomplete). Mirrors python_route_registry.py /
 * route-registry.ts.
 *
 * Run: php scripts/route_registry.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\REST\HttpClient;
use SignalWire\REST\RestClient;

// Path-param sentinel — one segment, no slash; already in {id} template form so
// the captured path lines up with the spec's path_template.
const SENTINEL = '{id}';

// Methods that do NOT map to a single canonical REST route, keyed by
// "<namespace>.<resource>.<method>" or a "<namespace>.<resource>.*" wildcard.
// Every entry needs a reason; a method that merely throws / issues no HTTP is an
// ERROR, not an implicit skip — add it here (justified) or fix the harness.
const REGISTRY_SKIP = [
    // cXML applications expose the CRUD surface but create is unsupported
    // (throws BadMethodCallException) — there is no POST /cxml_applications
    // canonical route. Mirrors python's fabric.cxml_applications.create.
    'fabric.cxmlApplications.create' => 'no create route — throws BadMethodCallException by design',
];

/**
 * Recording HttpClient: capture (verb, path) and return an empty array instead
 * of doing any network I/O. Subclasses the real client so every resource that
 * holds an HttpClient treats it identically.
 */
final class RecordingHttpClient extends HttpClient
{
    /**
     * Captured (verb, path) pairs since the last reset(). Private so the only
     * way to mutate it is through the verb methods / reset() below; callers read
     * it via calls(), whose declared return type tells PHPStan the slice can be
     * non-empty. (Exposing it as a public property would let a caller do
     * `$client->calls = []`, whose literal-[] narrowing PHPStan then can't see
     * an opaque Reflection invoke widen again — producing a bogus "always 0".)
     *
     * @var list<array{string,string}>
     */
    private array $calls = [];

    public function __construct()
    {
        // Bypass the real constructor (no auth/curl setup needed) — we never
        // touch the network. Parent properties stay unset; they are unused
        // because we override every verb method below.
    }

    /** Drop all captured calls (start a fresh recording window). */
    public function reset(): void
    {
        $this->calls = [];
    }

    /**
     * The calls captured since the last reset().
     *
     * @return list<array{string,string}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /** @param array<string,string> $params @return array<string,mixed> */
    public function get(string $path, array $params = []): array
    {
        $this->calls[] = ['GET', $path];
        return [];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function post(string $path, array $data = []): array
    {
        $this->calls[] = ['POST', $path];
        return [];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function put(string $path, array $data = []): array
    {
        $this->calls[] = ['PUT', $path];
        return [];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function patch(string $path, array $data = []): array
    {
        $this->calls[] = ['PATCH', $path];
        return [];
    }

    /** @return array<string,mixed> */
    public function delete(string $path): array
    {
        $this->calls[] = ['DELETE', $path];
        return [];
    }

    /** @param array<string,string> $params @return \Generator<int,array<string,mixed>> */
    public function listAll(string $path, array $params = []): \Generator
    {
        $this->calls[] = ['GET', $path];
        yield [];
    }
}

/**
 * Walks the live RestClient and records every implemented route.
 */
final class RouteRegistry
{
    private RecordingHttpClient $http;

    /** @var array<int,array{method:string,path_template:string,via:string}> */
    private array $routes = [];
    /** @var array<int,array{key:string,reason:string}> */
    private array $skipped = [];
    /** @var array<int,array{key:string,error:string}> */
    private array $errors = [];

    /** @var array<int,bool> object hashes already visited (cycle guard). */
    private array $seen = [];

    public function __construct(RecordingHttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * @return array{routes:array<int,array{method:string,path_template:string,via:array<int,string>}>,skipped:array<int,array{key:string,reason:string}>,errors:array<int,array{key:string,error:string}>}
     */
    public function build(RestClient $client): array
    {
        // Top-level namespace accessors on the client (fabric(), calling(), ...).
        foreach ($this->resourceAccessors($client) as [$name, $obj]) {
            $this->handle($name, $name, $obj);
        }

        // De-dup identical (method, path); collect every `via` accessor key.
        /** @var array<string,array{method:string,path_template:string,via:array<int,string>}> $byRoute */
        $byRoute = [];
        foreach ($this->routes as $r) {
            $k = $r['method'] . ' ' . $r['path_template'];
            if (isset($byRoute[$k])) {
                $byRoute[$k]['via'][] = $r['via'];
            } else {
                $byRoute[$k] = [
                    'method' => $r['method'],
                    'path_template' => $r['path_template'],
                    'via' => [$r['via']],
                ];
            }
        }
        $deduped = array_values($byRoute);
        usort($deduped, static function ($a, $b) {
            return ($a['path_template'] . $a['method']) <=> ($b['path_template'] . $b['method']);
        });

        return [
            'routes' => $deduped,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }

    /**
     * Handle one resource node: invoke its route methods and recurse into its
     * sub-resource accessors.
     */
    private function handle(string $nsName, string $resName, object $res): void
    {
        $hash = spl_object_id($res);
        if (isset($this->seen[$hash])) {
            return;
        }
        $this->seen[$hash] = true;

        $rc = new ReflectionClass($res);
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->isStatic() || $m->isConstructor() || $m->isDestructor()) {
                continue;
            }
            $name = $m->getName();
            if (str_starts_with($name, '__')) {
                continue;
            }

            $key = "{$nsName}.{$resName}.{$name}";
            $reason = $this->skipReason($key);
            if ($reason !== null) {
                $this->skipped[] = ['key' => $key, 'reason' => $reason];
                continue;
            }

            $kind = $this->classify($m);
            if ($kind === 'accessor') {
                // Sub-resource / namespace traversal — recurse, don't record.
                try {
                    $sub = $m->invoke($res);
                } catch (\Throwable $e) {
                    $this->errors[] = [
                        'key' => $key,
                        'error' => get_class($e) . ': ' . $e->getMessage(),
                    ];
                    continue;
                }
                if (is_object($sub)) {
                    $this->handle($nsName, $name, $sub);
                }
                continue;
            }
            if ($kind === 'infra') {
                // getHttp/getBasePath/getProjectId/... — neither a route nor a
                // sub-resource; not part of the dispatched-route surface.
                continue;
            }

            // kind === 'route': invoke with sentinels and capture the HTTP call.
            $this->http->reset();
            try {
                $args = $this->sentinelArgs($m);
                $m->invokeArgs($res, $args);
            } catch (\Throwable $e) {
                $this->errors[] = [
                    'key' => $key,
                    'error' => get_class($e) . ': ' . $e->getMessage(),
                ];
                continue;
            }
            $calls = $this->http->calls();
            if (count($calls) === 0) {
                $this->errors[] = [
                    'key' => $key,
                    'error' => 'invoked but issued no HTTP request '
                        . '(client-side helper? add to REGISTRY_SKIP with a reason)',
                ];
                continue;
            }
            foreach ($calls as [$verb, $path]) {
                $this->routes[] = [
                    'method' => $verb,
                    'path_template' => $path,
                    'via' => $key,
                ];
            }
        }
    }

    /**
     * Public, non-static, 0-required-arg methods on $obj whose return type is an
     * SDK resource/namespace class (not HttpClient) — the namespace accessors.
     *
     * @return array<int,array{0:string,1:object}>
     */
    private function resourceAccessors(object $obj): array
    {
        $out = [];
        $rc = new ReflectionClass($obj);
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->isStatic() || $m->isConstructor() || $m->isDestructor()) {
                continue;
            }
            if (str_starts_with($m->getName(), '__')) {
                continue;
            }
            if ($this->classify($m) !== 'accessor') {
                continue;
            }
            try {
                $sub = $m->invoke($obj);
            } catch (\Throwable $e) {
                $this->errors[] = [
                    'key' => $rc->getShortName() . '.' . $m->getName(),
                    'error' => get_class($e) . ': ' . $e->getMessage(),
                ];
                continue;
            }
            if (is_object($sub)) {
                $out[] = [$m->getName(), $sub];
            }
        }
        return $out;
    }

    /**
     * Classify a method by its return type:
     *   'accessor' — returns an SDK class (not HttpClient): a sub-resource.
     *   'route'    — returns array: a dispatched REST route.
     *   'infra'    — returns HttpClient / scalar / void / self: a getter.
     */
    private function classify(ReflectionMethod $m): string
    {
        $rt = $m->getReturnType();
        if ($rt instanceof ReflectionNamedType) {
            $tn = $rt->getName();
            if ($tn === 'array') {
                // A 0-required-arg array method is still a route (e.g. list()).
                return 'route';
            }
            if (in_array($tn, ['string', 'int', 'bool', 'float', 'void', 'mixed', 'self', 'static', 'never'], true)) {
                return 'infra';
            }
            // A class return type. HttpClient is infra; any other SDK class is a
            // sub-resource accessor — but only if it needs no arguments to reach.
            if (is_a($tn, HttpClient::class, true)) {
                return 'infra';
            }
            if ($this->hasNoRequiredParams($m)) {
                return 'accessor';
            }
            // A class-returning method that needs args is neither — treat as infra
            // (no such method exists on the REST surface today; defensive).
            return 'infra';
        }
        // No declared return type — fall back to infra (none on REST surface).
        return 'infra';
    }

    private function hasNoRequiredParams(ReflectionMethod $m): bool
    {
        foreach ($m->getParameters() as $p) {
            if (!$p->isOptional()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Build sentinel arguments for a route method, matching each required
     * param's declared type: `string`/untyped → "{id}", `array` → [],
     * `int` → 0, `float` → 0.0, `bool` → false. Optional / variadic params get
     * nothing. Scalar-typed required params exist because the generated methods
     * now carry typed named params (e.g. `int $ttl`, `float $volume`); passing
     * the string path sentinel to them would raise a TypeError and drop the
     * route from Set B.
     *
     * @return array<int,mixed>
     */
    private function sentinelArgs(ReflectionMethod $m): array
    {
        $args = [];
        foreach ($m->getParameters() as $p) {
            if ($p->isOptional() || $p->isVariadic()) {
                continue;
            }
            $t = $p->getType();
            $name = ($t instanceof ReflectionNamedType) ? $t->getName() : '';
            switch ($name) {
                case 'array':
                    $args[] = [];
                    break;
                case 'int':
                    $args[] = 0;
                    break;
                case 'float':
                    $args[] = 0.0;
                    break;
                case 'bool':
                    $args[] = false;
                    break;
                default:
                    // string (resource id / sid) and everything else → the path sentinel.
                    $args[] = SENTINEL;
                    break;
            }
        }
        return $args;
    }

    private function skipReason(string $key): ?string
    {
        if (isset(REGISTRY_SKIP[$key])) {
            return REGISTRY_SKIP[$key];
        }
        $pos = strrpos($key, '.');
        if ($pos !== false) {
            $wildcard = substr($key, 0, $pos) . '.*';
            if (isset(REGISTRY_SKIP[$wildcard])) {
                return REGISTRY_SKIP[$wildcard];
            }
        }
        return null;
    }
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$client = new RestClient('p', 't', 'example.signalwire.com');

// Swap the client's private HttpClient for the recorder. RestClient builds its
// own HttpClient in __construct (and caches namespace objects lazily, all still
// null at this point), so re-pointing the private $http here means every
// namespace accessor wires its resources to the recorder. Mirrors python's
// HttpClient monkeypatch / ts's fetchImpl injection.
$recorder = new RecordingHttpClient();
$ref = new ReflectionProperty(RestClient::class, 'http');
$ref->setAccessible(true);
$ref->setValue($client, $recorder);

$registry = new RouteRegistry($recorder);
$out = $registry->build($client);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

exit(count($out['errors']) > 0 ? 1 : 0);
