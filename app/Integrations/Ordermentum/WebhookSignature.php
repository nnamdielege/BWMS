<?php

namespace App\Integrations\Ordermentum;

use Illuminate\Http\Request;

/**
 * Ordermentum webhook signature (confirmed by Ordermentum support, 2026-10-27):
 * header `x-ordermentum-signature` = HMAC-SHA256 of the raw body, keyed with the
 * supplier's `sharedSecret` (GET /v1/suppliers/{supplierId}; `om:probe secret`).
 * The digest is accepted hex- or base64-encoded (optionally "sha256=" prefixed);
 * which one matched is recorded so the encoding can be pinned once seen live.
 */
class WebhookSignature
{
    public const VALID_HEX = 'valid_hex';

    public const VALID_BASE64 = 'valid_base64';

    public const INVALID = 'invalid';

    public const MISSING = 'missing';

    public const UNCONFIGURED = 'unconfigured';

    public function check(Request $request): string
    {
        $header = (string) config('bwms.ordermentum.webhook_signature.header');
        $secret = (string) config('bwms.ordermentum.webhook_signature.secret');
        if ($header === '' || $secret === '') {
            return self::UNCONFIGURED;
        }

        $given = trim((string) $request->header($header));
        if ($given === '') {
            return self::MISSING;
        }
        $given = preg_replace('/^sha256=/i', '', $given);
        $raw = hash_hmac((string) config('bwms.ordermentum.webhook_signature.algorithm'), $request->getContent(), $secret, true);

        return match (true) {
            hash_equals(bin2hex($raw), strtolower($given)) => self::VALID_HEX,
            hash_equals(base64_encode($raw), $given) => self::VALID_BASE64,
            default => self::INVALID,
        };
    }

    public static function isValid(string $result): bool
    {
        return in_array($result, [self::VALID_HEX, self::VALID_BASE64], true);
    }

    public function enforced(): bool
    {
        return config('bwms.ordermentum.webhook_signature.mode') === 'enforce';
    }

    public function shouldReject(string $result): bool
    {
        return $this->enforced() && ! self::isValid($result);
    }
}
