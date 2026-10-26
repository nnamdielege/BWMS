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
 * Guards: the long random token in the path, a configurable signature check
 * (report-only until Ordermentum confirms the scheme), and the job re-fetching
 * the order from the API before acting on it.
 */
class OrdermentumWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, OrderInbox $inbox, WebhookSignature $signature): JsonResponse
    {
        $expected = (string) config('bwms.ordermentum.webhook_token');
        if ($expected === '' || ! hash_equals($expected, $token)) {
            abort(404);
        }

        $headers = collect($request->headers->all())->except(['authorization', 'cookie', 'php-auth-pw'])
            ->map(fn ($v) => implode(', ', $v))->all();
        $result = $signature->check($request);
        // Headers only (never the body or the path token), to identify Ordermentum's signature header.
        Log::info('Ordermentum webhook received', ['headers' => $headers, 'signature' => $result]);

        if ($signature->shouldReject($result)) {
            return response()->json(['error' => 'Invalid signature.'], 401);
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
