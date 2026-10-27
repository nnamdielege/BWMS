<?php

namespace App\Http\Middleware;

use App\Integrations\Portal\PortalSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates portal API calls (spec B4.4): known key ID, timestamp within
 * 300s, valid HMAC signature over timestamp + raw body. Any failure is a 401
 * with no detail beyond the reason code.
 */
class VerifyPortalSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('bwms.portal.api_enabled')) {
            return response()->json(['error' => 'portal_api_disabled'], 503);
        }

        $keys = config('bwms.portal.inbound_keys');
        $keyId = (string) $request->header(PortalSignature::KEY_HEADER);
        $timestamp = (string) $request->header(PortalSignature::TIMESTAMP_HEADER);
        $signature = strtolower((string) $request->header(PortalSignature::SIGNATURE_HEADER));

        $error = match (true) {
            $keyId === '' || ! isset($keys[$keyId]) => 'unknown_key',
            ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > config('bwms.portal.timestamp_tolerance') => 'stale_timestamp',
            ! hash_equals(PortalSignature::sign($timestamp, $request->getContent(), $keys[$keyId]), $signature) => 'bad_signature',
            default => null,
        };
        if ($error) {
            return response()->json(['error' => $error], 401);
        }

        $request->attributes->set('portal_key_id', $keyId);

        return $next($request);
    }
}
