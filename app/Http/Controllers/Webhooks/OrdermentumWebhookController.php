<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Integrations\Ordermentum\OrderInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NFR-5: authenticate, store raw, answer 200 fast, process async, dedupe.
 * Ordermentum offers no signing (B3.1), so the long random token in the path
 * is the authentication, and the payload is only a hint: the job re-fetches
 * the order from the API before acting on it.
 */
class OrdermentumWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, OrderInbox $inbox): JsonResponse
    {
        $expected = (string) config('bwms.ordermentum.webhook_token');
        if ($expected === '' || ! hash_equals($expected, $token)) {
            abort(404);
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
            collect($request->headers->all())->except(['authorization', 'cookie', 'php-auth-pw'])->map(fn ($v) => implode(', ', $v))->all(),
        );

        return response()->json(['ok' => true]);
    }
}
