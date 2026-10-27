<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Integrations\Ordermentum\OrderInbox;
use App\Integrations\Ordermentum\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * NFR-5: authenticate, store raw, answer 200 fast, process async, dedupe.
 * Guards: Ordermentum's HMAC signature (enforced: 401 on mismatch), the secret
 * path token (optional once signatures are verified on a real event; it can only
 * be dropped while enforcement is on), and the job re-fetching the order from
 * the API before acting on it.
 */
class OrdermentumWebhookController extends Controller
{
    public function __invoke(Request $request, OrderInbox $inbox, WebhookSignature $signature, ?string $token = null): JsonResponse
    {
        $expected = (string) config('bwms.ordermentum.webhook_token');
        $requireToken = (bool) config('bwms.ordermentum.webhook_require_token') || ! $signature->enforced();
        if ($requireToken && ($expected === '' || $token === null)) {
            abort(404);
        }
        if ($token !== null && ($expected === '' || ! hash_equals($expected, $token))) {
            abort(404);
        }

        $headers = collect($request->headers->all())->except(['authorization', 'cookie', 'php-auth-pw'])
            ->map(fn ($v) => implode(', ', $v))->all();
        $result = $signature->check($request);
        // Header names only (no values, body or path token), plus which signature encoding matched.
        Log::info('Ordermentum webhook received', ['header_names' => array_keys($headers), 'signature' => $result]);

        if ($signature->shouldReject($result)) {
            if ($result === WebhookSignature::UNCONFIGURED) {
                Log::error('Ordermentum webhook rejected: ORDERMENTUM_WEBHOOK_SECRET is not set (run om:probe secret --write-env).');
            }

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $raw = $request->getContent();
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['error' => 'Body must be JSON.'], 400);
        }

        $inbox->record(
            (string) ($payload['event'] ?? 'unknown'),
            isset($payload['data']['id']) ? (string) $payload['data']['id'] : null,
            isset($payload['data']['updatedAt']) ? (string) $payload['data']['updatedAt'] : null,
            $raw,
            $headers,
            $result,
        );

        return response()->json(['ok' => true]);
    }
}
