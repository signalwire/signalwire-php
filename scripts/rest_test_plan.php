<?php

declare(strict_types=1);

/**
 * rest_test_plan.php — per-`via` call plan for the REST wire-test generator.
 *
 * Companion capture to scripts/route_registry.php. route_registry.php answers
 * "which (method, path) routes does the SDK implement" (deduped, via-merged) for
 * the SPEC-PARITY gate; this script answers the sibling question the TEST
 * generator needs: for EVERY `via` accessor method, what is the exact PHP call
 * expression that reaches it AND what type-correct sentinel arguments must be
 * passed. It is the PHP realisation of the reflection the go/ts generators do in
 * their own language (signalwire-go/cmd/generate-rest-tests buildCallIndex,
 * signalwire-typescript/scripts/generate-rest-tests.ts SignatureResolver).
 *
 * It reuses the SAME live-client walk + structural classification as
 * route_registry.php (recording HttpClient, reflect every namespace/sub-resource
 * public method) so the capture can never drift from the registry's route set.
 * For each route method it records, per `via`:
 *   - method    : the HTTP verb captured from the recording client
 *   - path      : the captured path template (params already {id})
 *   - chain     : the ordered accessor call chain to reach the method — e.g.
 *                 ["video","rooms"] for video.rooms.listStreams, or ["calling"]
 *                 for the flat calling.calling.dial (the leading duplicate
 *                 namespace segment is collapsed, mirroring go/ts attrPath).
 *   - member    : the route method name (listStreams, create, ...).
 *   - args      : the ordered type-correct sentinel literals for the method's
 *                 REQUIRED params (string→"x", int→1, float→1.0, bool→true,
 *                 array→[]). Optional / variadic params are omitted. These are
 *                 emitted verbatim into the generated PHP call.
 *
 * The `via` key is "<ns>.<res>.<member>" — identical to route_registry.php's via
 * strings — so the Python generator joins registry routes → spec operationId →
 * this plan by via with no ambiguity.
 *
 * Output: JSON {"plan":[{via,method,path,chain,member,args}],"errors":[...]} on
 * stdout. Exit 1 if any route method could not be reflected (never silently
 * dropped — a dropped via is a hole in the generated suite).
 *
 * Run: php scripts/rest_test_plan.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\REST\HttpClient;
use SignalWire\REST\PaginatedIterator;
use SignalWire\REST\RestClient;

const SENTINEL = '{id}';

// Mirror route_registry.php's REGISTRY_SKIP: methods that dispatch no single
// canonical route (so route_registry.php skips them and they are not covered).
const PLAN_SKIP = [
    'fabric.cxmlApplications.create' => 'no create route — throws BadMethodCallException by design',
];

/**
 * Recording HttpClient: capture (verb, path) and return [] — no network I/O.
 * Identical contract to route_registry.php's recorder.
 */
final class PlanRecordingHttpClient extends HttpClient
{
    /** @var list<array{string,string}> */
    private array $calls = [];

    public function __construct()
    {
        // Bypass the real constructor; every verb is overridden below.
    }

    public function reset(): void
    {
        $this->calls = [];
    }

    /** @return list<array{string,string}> */
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

final class TestPlan
{
    private PlanRecordingHttpClient $http;

    /** @var array<int,array{via:string,method:string,path:string,chain:array<int,string>,member:string,args:array<int,string>}> */
    private array $plan = [];
    /** @var array<int,array{key:string,error:string}> */
    private array $errors = [];
    /** @var array<int,bool> */
    private array $seen = [];

    public function __construct(PlanRecordingHttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * @return array{plan:array<int,array{via:string,method:string,path:string,chain:array<int,string>,member:string,args:array<int,string>}>,errors:array<int,array{key:string,error:string}>}
     */
    public function build(RestClient $client): array
    {
        foreach ($this->resourceAccessors($client) as [$name, $obj]) {
            // The top-level accessor name is BOTH the namespace segment and the
            // start of the call chain. handle() carries the chain reaching $obj.
            $this->handle($name, $name, $obj, [$name]);
        }
        return ['plan' => $this->plan, 'errors' => $this->errors];
    }

    /**
     * @param list<string> $chain accessor call chain reaching $res
     */
    private function handle(string $nsName, string $resName, object $res, array $chain): void
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
            if ($this->skipReason($key) !== null) {
                continue;
            }

            $kind = $this->classify($m);
            if ($kind === 'accessor') {
                try {
                    $sub = $m->invoke($res);
                } catch (\Throwable $e) {
                    $this->errors[] = ['key' => $key, 'error' => get_class($e) . ': ' . $e->getMessage()];
                    continue;
                }
                if (is_object($sub)) {
                    // Recurse; extend the call chain with this accessor.
                    $this->handle($nsName, $name, $sub, array_merge($chain, [$name]));
                }
                continue;
            }
            if ($kind === 'infra') {
                continue;
            }

            // kind === 'route': capture the (verb, path) and the typed sentinels.
            $this->http->reset();
            try {
                $args = $this->sentinelInvokeArgs($m);
                $m->invokeArgs($res, $args);
            } catch (\Throwable $e) {
                $this->errors[] = ['key' => $key, 'error' => get_class($e) . ': ' . $e->getMessage()];
                continue;
            }
            $calls = $this->http->calls();
            if (count($calls) === 0) {
                $this->errors[] = [
                    'key' => $key,
                    'error' => 'invoked but issued no HTTP request',
                ];
                continue;
            }
            // A route method funnels through exactly one verb call in the common
            // case; if it made several (a compound helper), the FIRST is the
            // canonical route (matches route_registry's per-call fan-out, whose
            // dedup keeps one route per (method,path)). We record the first.
            [$verb, $path] = $calls[0];
            $this->plan[] = [
                'via' => $key,
                'method' => $verb,
                'path' => $path,
                // Collapse a leading duplicate namespace segment for the emitted
                // call chain (calling.calling.dial → $client->calling()->dial()).
                'chain' => $this->collapseChain($chain),
                'member' => $name,
                'args' => $this->sentinelLiteralArgs($m),
            ];
        }
    }

    /**
     * Collapse a leading duplicate accessor segment, mirroring go/ts attrPath:
     * a flat namespace's chain is [calling, calling] → [calling]; a container
     * chain [video, rooms] is unchanged.
     *
     * @param list<string> $chain
     * @return list<string>
     */
    private function collapseChain(array $chain): array
    {
        if (count($chain) >= 2 && $chain[0] === $chain[1]) {
            return array_slice($chain, 1);
        }
        return $chain;
    }

    /**
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

    private function classify(ReflectionMethod $m): string
    {
        $rt = $m->getReturnType();
        if ($rt instanceof ReflectionNamedType) {
            $tn = $rt->getName();
            if ($tn === 'array') {
                return 'route';
            }
            if (in_array($tn, ['string', 'int', 'bool', 'float', 'void', 'mixed', 'self', 'static', 'never'], true)) {
                return 'infra';
            }
            if (is_a($tn, HttpClient::class, true)) {
                return 'infra';
            }
            // paginate() returns a PaginatedIterator: a terminal iterator over the
            // SAME list route already reached via list(), dispatching no new route.
            // Not a navigable sub-resource — exclude structurally (like HttpClient).
            if (is_a($tn, PaginatedIterator::class, true)) {
                return 'infra';
            }
            if ($this->hasNoRequiredParams($m)) {
                return 'accessor';
            }
            return 'infra';
        }
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
     * Sentinel arguments to INVOKE a route method during capture — matching
     * route_registry.php::sentinelArgs so the recorded (verb, path) agrees.
     *
     * @return array<int,mixed>
     */
    private function sentinelInvokeArgs(ReflectionMethod $m): array
    {
        $args = [];
        foreach ($m->getParameters() as $p) {
            if ($p->isOptional() || $p->isVariadic()) {
                continue;
            }
            $args[] = match ($this->paramTypeName($p)) {
                'array' => [],
                'int' => 0,
                'float' => 0.0,
                'bool' => false,
                default => SENTINEL,
            };
        }
        return $args;
    }

    /**
     * Type-correct sentinel LITERALS (PHP source text) for a method's required
     * params — emitted into the generated call. A string path-id / body value →
     * 'x' (a valid one-segment id AND a valid body string); int → 1; float →
     * 1.0; bool → true; array → []. Required only; optional/variadic omitted.
     *
     * @return array<int,string>
     */
    private function sentinelLiteralArgs(ReflectionMethod $m): array
    {
        $args = [];
        foreach ($m->getParameters() as $p) {
            if ($p->isOptional() || $p->isVariadic()) {
                continue;
            }
            $args[] = match ($this->paramTypeName($p)) {
                'array' => '[]',
                'int' => '1',
                'float' => '1.0',
                'bool' => 'true',
                default => "'x'",
            };
        }
        return $args;
    }

    private function paramTypeName(ReflectionParameter $p): string
    {
        $t = $p->getType();
        return ($t instanceof ReflectionNamedType) ? $t->getName() : '';
    }

    private function skipReason(string $key): ?string
    {
        if (isset(PLAN_SKIP[$key])) {
            return PLAN_SKIP[$key];
        }
        $pos = strrpos($key, '.');
        if ($pos !== false) {
            $wildcard = substr($key, 0, $pos) . '.*';
            if (isset(PLAN_SKIP[$wildcard])) {
                return PLAN_SKIP[$wildcard];
            }
        }
        return null;
    }
}

$client = new RestClient('p', 't', 'example.signalwire.com');

$recorder = new PlanRecordingHttpClient();
$ref = new ReflectionProperty(RestClient::class, 'http');
$ref->setAccessible(true);
$ref->setValue($client, $recorder);

$plan = (new TestPlan($recorder))->build($client);

echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

exit(count($plan['errors']) > 0 ? 1 : 0);
