<?php

namespace App\Integrations\Portal;

/**
 * Portal <-> BWMS request signing (spec B4.4): hex HMAC-SHA256 of
 * "{timestamp}.{raw body}" with the shared secret for the key ID. Used for
 * inbound verification and outbound signing alike.
 */
final class PortalSignature
{
    public const KEY_HEADER = 'X-BWMS-Key-Id';

    public const TIMESTAMP_HEADER = 'X-BWMS-Timestamp';

    public const SIGNATURE_HEADER = 'X-BWMS-Signature';

    public static function sign(string $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** @return array<string, string> headers for an outbound request */
    public static function headers(string $keyId, string $secret, string $body, ?int $now = null): array
    {
        $timestamp = (string) ($now ?? time());

        return [
            self::KEY_HEADER => $keyId,
            self::TIMESTAMP_HEADER => $timestamp,
            self::SIGNATURE_HEADER => self::sign($timestamp, $body, $secret),
        ];
    }
}
