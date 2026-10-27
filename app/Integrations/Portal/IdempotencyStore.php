<?php

namespace App\Integrations\Portal;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Idempotency-Key handling for mutating API calls (spec B4.4). The stored
 * response is returned for a replay with the same body; the same key with a
 * different body is 422 idempotency_conflict. 5xx responses are not stored,
 * so the caller's retry runs again.
 */
class IdempotencyStore
{
    /** @param  Closure(): JsonResponse  $handler */
    public function remember(Request $request, string $client, Closure $handler): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 191) {
            return response()->json(['error' => 'idempotency_key_required'], 400);
        }
        $hash = hash('sha256', $request->method().' '.$request->path()."\n".$request->getContent());

        $stored = DB::table('api_idempotency_keys')->where('client', $client)->where('idempotency_key', $key)->first();
        if ($stored) {
            return $stored->request_hash === $hash
                ? response()->json(json_decode($stored->response_body, true), $stored->response_status, ['Idempotent-Replay' => 'true'])
                : response()->json(['error' => 'idempotency_conflict'], 422);
        }

        $response = $handler();
        if ($response->getStatusCode() < 500) {
            DB::table('api_idempotency_keys')->insertOrIgnore([
                'client' => $client, 'idempotency_key' => $key, 'request_hash' => $hash,
                'response_status' => $response->getStatusCode(), 'response_body' => $response->getContent(), 'created_at' => now(),
            ]);
        }

        return $response;
    }
}
