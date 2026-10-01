<?php

declare(strict_types=1);

namespace SignalWire\Core;

/**
 * Reading what a client says it can do.
 *
 * A browser client -- the SignalWire address widget, or anything speaking the
 * same convention -- declares its rendering capabilities in the user variables
 * it sends at dial time:
 *
 *     {"vars": {"userVariables": {
 *         "capabilities": {"display_content": true, "transcript": true, "chat_handoff": false},
 *         "metadata": {"page": {...}, "client": {...}, "widget": {...}}
 *     }}}
 *
 * **These are declarations of what the client can RENDER, not grants of
 * authority.** Treat them as hints for deciding what to offer, never as
 * permission to do anything privileged: a caller controls its own user
 * variables.
 *
 * **Absence means no.** Every helper resolves errors and missing data to "not
 * declared": a PSTN caller has no browser, and offering to "put that on your
 * screen" to someone on a phone is worse than never mentioning it.
 *
 * There is deliberately no enum of capability names: a client can declare
 * something this SDK has never heard of and an application can act on it.
 */
final class Capabilities
{
    /**
     * Return the user variables from a SWML request body (`vars.userVariables`),
     * or `[]` when any level is missing or malformed.
     *
     * @param mixed $bodyParams The SWML request body.
     * @return array<string, mixed>
     */
    public static function userVariables(mixed $bodyParams): array
    {
        if (!is_array($bodyParams)) {
            return [];
        }
        $vars = $bodyParams['vars'] ?? [];
        if (!is_array($vars)) {
            return [];
        }
        $variables = $vars['userVariables'] ?? [];
        return self::assoc($variables);
    }

    /**
     * Return the capability names the client declared as truthy, sorted.
     *
     * Accepts either a full SWML request body or an already-extracted user
     * variables array, so it is usable from a dynamic-config callback and from
     * a SWAIG handler alike. Empty when nothing was declared, the payload was
     * malformed, or the client is not a browser at all.
     *
     *     if (in_array('display_content', Capabilities::declaredCapabilities($body), true)) {
     *         $agent->promptAddSection('Screen', body: '...');
     *     }
     *
     * @param mixed $bodyParams SWML request body, or a user variables array.
     * @return list<string>
     */
    public static function declaredCapabilities(mixed $bodyParams): array
    {
        $variables = self::userVariables($bodyParams);
        if ($variables === [] && is_array($bodyParams)) {
            // Already-extracted user variables were passed directly.
            $variables = self::assoc($bodyParams);
        }
        $capabilities = $variables['capabilities'] ?? null;
        if (!is_array($capabilities) || array_is_list($capabilities) && $capabilities !== []) {
            return [];
        }
        $names = [];
        foreach ($capabilities as $name => $value) {
            if (is_string($name) && self::truthy($value)) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    /**
     * Whether the client declared `$name` -- true only when explicitly
     * declared truthy.
     *
     * @param mixed $bodyParams SWML request body, or a user variables array.
     * @param string $name Capability name, e.g. "display_content".
     */
    public static function hasCapability(mixed $bodyParams, string $name): bool
    {
        return in_array($name, self::declaredCapabilities($bodyParams), true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function assoc(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    /** JSON truthiness as the reference reads it: false/null/0/""/[]/{} are not declared. */
    private static function truthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === 0 || $value === 0.0
            || $value === '' || $value === []);
    }
}
