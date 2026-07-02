<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

use SignalWire\Logging\Logger;

/**
 * @internal Internal LiveWire noop-advisory helper.
 *
 * LiveWire pipeline options (stt/tts/vad/turn-detection/...) are accepted for
 * LiveKit source-compatibility but are no-ops on SignalWire — the control plane
 * handles the full media pipeline server-side. This trait logs each advisory at
 * most once (keyed) so exercising the same no-op path repeatedly does not spam.
 *
 * Not part of the public surface: composed as a trait, methods are protected,
 * and a trait scope is excluded from the enumerated surface entirely.
 */
trait NoopLog
{
    /**
     * Process-wide dedup registry, shared across every LiveWire class.
     *
     * @var array<string, bool>
     */
    private static array $noopLogged = [];

    /**
     * Log $message once for $key. Returns true the first time (when logged).
     */
    protected static function noopOnce(string $key, string $message): bool
    {
        if (isset(self::$noopLogged[$key])) {
            return false;
        }
        self::$noopLogged[$key] = true;
        Logger::getLogger('LiveWire')->info('[LiveWire] ' . $message);
        return true;
    }
}
