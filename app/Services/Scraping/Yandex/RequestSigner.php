<?php

declare(strict_types=1);

namespace App\Services\Scraping\Yandex;

/**
 * Signs requests to the Yandex.Maps internal API.
 *
 * The internal reviews endpoint rejects any request without an `s` parameter.
 * It is not a cryptographic signature and not a secret — it is a checksum over
 * the parameter set that Yandex's own frontend computes in the browser. The
 * algorithm was recovered from their client bundle and has two steps:
 *
 *   1. parameters are serialised into a query string with keys sorted
 *      case-insensitively (the `qs` library, RFC 3986 encoding);
 *   2. a DJB2-with-XOR variant is hashed over that string and the result is
 *      coerced to an unsigned 32-bit integer.
 *
 * The original, minified, from the maps bundle:
 *
 *   var u = function(e) {
 *       var t = s.stringify(e, {sort: function(e,t){
 *           var r = e.toLowerCase(), n = t.toLowerCase();
 *           return r < n ? -1 : r > n ? 1 : 0;
 *       }});
 *       return t ? String(function(e){
 *           for (var t = e.length, r = 5381, n = 0; n < t; n++)
 *               r = 33 * r ^ e.charCodeAt(n);
 *           return r >>> 0;
 *       }(t)) : "";
 *   };
 *
 * This is the most brittle part of the integration: if Yandex changes the
 * algorithm the fast path breaks entirely. Hence it is isolated in its own
 * class, covered by tests with reference vectors, and the source keeps a
 * fallback strategy.
 */
final class RequestSigner
{
    private const HASH_SEED = 5381;

    /**
     * Build a signed query string.
     *
     * @param  array<string, scalar|null>  $params
     */
    public function sign(array $params): string
    {
        $query = $this->buildQuery($params);

        return $query.'&s='.$this->hash($query);
    }

    /**
     * Serialisation in the style of `qs.stringify` with case-insensitive key sorting.
     *
     * @param  array<string, scalar|null>  $params
     */
    public function buildQuery(array $params): string
    {
        uksort($params, static fn (string $a, string $b): int => strcasecmp($a, $b));

        $pairs = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                // qs serialises null as a key with an empty value rather than dropping it
                $pairs[] = $this->encode($key).'=';

                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $pairs[] = $this->encode($key).'='.$this->encode((string) $value);
        }

        return implode('&', $pairs);
    }

    /**
     * DJB2 with XOR — bit-for-bit identical to the browser implementation.
     *
     * In JavaScript `33 * r` is computed as a double and then coerced to int32
     * by the XOR operator. The intermediate product never exceeds 2^53, so no
     * precision is lost, and masking with 0xFFFFFFFF reproduces the coercion
     * to 32 bits.
     */
    public function hash(string $value): string
    {
        $hash = self::HASH_SEED;

        foreach ($this->utf16CodeUnits($value) as $unit) {
            $hash = (($hash * 33) & 0xFFFFFFFF) ^ $unit;
        }

        return (string) ($hash & 0xFFFFFFFF);
    }

    /**
     * `String.prototype.charCodeAt` operates on UTF-16 code units, not code
     * points: characters outside the BMP yield a surrogate pair. For ASCII
     * parameters this makes no difference, but the behaviour is reproduced
     * exactly — otherwise any non-Latin parameter would silently corrupt the
     * signature.
     *
     * @return list<int>
     */
    private function utf16CodeUnits(string $value): array
    {
        $utf16 = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
        $units = unpack('v*', $utf16);

        return $units === false ? [] : array_values($units);
    }

    /**
     * Percent-encoding per RFC 3986: only A-Z a-z 0-9 - _ . ~ stay unreserved
     * (a space becomes %20, never +).
     */
    private function encode(string $value): string
    {
        return rawurlencode($value);
    }
}
