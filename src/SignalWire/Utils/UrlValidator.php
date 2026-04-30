<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Utils;

use SignalWire\Logging\Logger;

/**
 * SSRF-prevention guard for user-supplied URLs.
 *
 * Mirrors Python's signalwire.utils.url_validator.validate_url:
 * rejects non-http(s) schemes, missing hostnames, and any URL whose
 * hostname resolves to a private / loopback / link-local / cloud-metadata
 * IP. The $allowPrivate parameter, OR the SWML_ALLOW_PRIVATE_URLS env
 * var with value "1", "true" or "yes" (case-insensitive), bypasses the
 * IP-blocklist check.
 *
 * The static method validateUrl projects onto the Python free function
 * signalwire.utils.url_validator.validate_url via
 * scripts/enumerate_signatures.py.
 */
final class UrlValidator
{
    /** @var string[] Cross-port SSRF block list. Order matches the Python reference. */
    public const BLOCKED_NETWORKS = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16',  // link-local / cloud metadata
        '0.0.0.0/8',
        '::1/128',
        'fc00::/7',  // IPv6 private (ULA)
        'fe80::/10', // IPv6 link-local
    ];

    /**
     * Pluggable resolver. Tests inject a callable to keep the suite
     * hermetic; production calls dns_get_record/gethostbynamel.
     *
     * Signature: function(string $hostname): ?array (array of IP
     * strings, or null on resolution failure).
     *
     * @var (callable(string): ?array)|null
     */
    public static $resolver = null;

    /**
     * Validate that a URL is safe to fetch.
     *
     * @param string $url           URL to validate.
     * @param bool   $allowPrivate  When true, bypass the IP-blocklist check.
     */
    public static function validateUrl(string $url, bool $allowPrivate = false): bool
    {
        $log = Logger::getLogger('signalwire.url_validator');

        $parsed = @parse_url($url);
        if ($parsed === false || !is_array($parsed)) {
            $log->warn('URL validation error: malformed URL');
            return false;
        }
        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            $log->warn('URL rejected: invalid scheme ' . ($parsed['scheme'] ?? '(none)'));
            return false;
        }
        $hostname = $parsed['host'] ?? '';
        if ($hostname === '') {
            $log->warn('URL rejected: no hostname');
            return false;
        }
        // PHP includes the brackets in $parsed['host'] for IPv6
        if (str_starts_with($hostname, '[') && str_ends_with($hostname, ']')) {
            $hostname = substr($hostname, 1, -1);
        }

        if ($allowPrivate || self::envAllowsPrivate()) {
            return true;
        }

        $ips = self::resolve($hostname);
        if ($ips === null || count($ips) === 0) {
            $log->warn('URL rejected: could not resolve hostname ' . $hostname);
            return false;
        }

        foreach ($ips as $ip) {
            foreach (self::BLOCKED_NETWORKS as $cidr) {
                if (self::cidrContains($cidr, $ip)) {
                    $log->warn(
                        'URL rejected: ' . $hostname . ' resolves to blocked IP ' .
                        $ip . ' (in ' . $cidr . ')'
                    );
                    return false;
                }
            }
        }

        return true;
    }

    private static function envAllowsPrivate(): bool
    {
        $v = getenv('SWML_ALLOW_PRIVATE_URLS');
        if ($v === false || $v === '') {
            return false;
        }
        $low = strtolower($v);
        return $low === '1' || $low === 'true' || $low === 'yes';
    }

    /**
     * Resolve $hostname to an array of IP-string addresses, or null on
     * failure. Recognises literal IPv4/IPv6 inputs.
     *
     * @return string[]|null
     */
    private static function resolve(string $hostname): ?array
    {
        if (self::$resolver !== null) {
            $r = self::$resolver;
            return $r($hostname);
        }
        // Literal IPs short-circuit DNS.
        if (filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            return [$hostname];
        }
        $records = @dns_get_record($hostname, DNS_A | DNS_AAAA);
        if ($records === false) {
            return null;
        }
        $ips = [];
        foreach ($records as $r) {
            if (!empty($r['ip'])) {
                $ips[] = $r['ip'];
            } elseif (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if (count($ips) === 0) {
            // Fall back to gethostbynamel (IPv4 only) which is more lenient
            $alt = @gethostbynamel($hostname);
            if (is_array($alt) && count($alt) > 0) {
                return $alt;
            }
            return null;
        }
        return $ips;
    }

    /**
     * Test whether a single IP-string falls inside the supplied CIDR.
     * Handles both IPv4 and IPv6.
     */
    private static function cidrContains(string $cidr, string $ip): bool
    {
        $slash = strpos($cidr, '/');
        if ($slash === false) {
            return false;
        }
        $netStr = substr($cidr, 0, $slash);
        $prefix = (int) substr($cidr, $slash + 1);

        $netBin = @inet_pton($netStr);
        $ipBin = @inet_pton($ip);
        if ($netBin === false || $ipBin === false) {
            return false;
        }
        if (strlen($netBin) !== strlen($ipBin)) {
            return false; // IPv4 vs IPv6 mismatch
        }
        $totalBits = strlen($netBin) * 8;
        if ($prefix < 0 || $prefix > $totalBits) {
            return false;
        }
        $fullBytes = intdiv($prefix, 8);
        $remBits = $prefix % 8;
        if ($fullBytes > 0 && substr($netBin, 0, $fullBytes) !== substr($ipBin, 0, $fullBytes)) {
            return false;
        }
        if ($remBits > 0) {
            $mask = chr((0xFF << (8 - $remBits)) & 0xFF);
            if ((ord($netBin[$fullBytes]) & ord($mask)) !== (ord($ipBin[$fullBytes]) & ord($mask))) {
                return false;
            }
        }
        return true;
    }
}
