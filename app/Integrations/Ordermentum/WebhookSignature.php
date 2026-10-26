<?php

namespace App\Integrations\Ordermentum;

use Illuminate\Http\Request;

/**
 * Ordermentum signs webhooks, but the header name and algorithm are not
 * confirmed yet (asked 2026-10-27). This checks an HMAC of the raw body
 * against a configurable header (hex or base64, optional "sha256=" prefix).
 * Until the scheme is confirmed it runs in "report" mode: results are recorded
 * on the inbox row and never reject a delivery; the path token and fetching
 * the order back from the API remain the guard.
 */
class WebhookSignature
{
    public const VALID = 'valid';

    public const INVALID = 'invalid';

    public const MISSING = 'missing';

    public const UNCONFIGURED = 'unconfigured';

    public function check(Request $request): string
    {
        $header = config('bwms.ordermentum.webhook_signature.header');
        $secret = (string) config('bwms.ordermentum.webhook_signature.secret');
        if (blank($header) || $secret === '') {
            return self::UNCONFIGURED;
        }

        $given = trim((string) $request->header($header));
        if ($given === '') {
            return self::MISSING;
        }
        $algo = (string) config('bwms.ordermentum.webhook_signature.algorithm');
        $given = preg_replace('/^[a-z0-9]+=/i', '', $given);
        $raw = hash_hmac($algo, $request->getContent(), $secret, true);

        return hash_equals(bin2hex($raw), strtolower($given)) || hash_equals(base64_encode($raw), $given)
            ? self::VALID
            : self::INVALID;
    }

    public function shouldReject(string $result): bool
    {
        return config('bwms.ordermentum.webhook_signature.mode') === 'enforce' && $result !== self::VALID;
    }
}
