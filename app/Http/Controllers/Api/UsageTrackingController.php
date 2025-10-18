<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UsageTrackingService;
use Illuminate\Http\Request;

class UsageTrackingController extends Controller
{
    protected $usageService;

    public function __construct(UsageTrackingService $usageService)
    {
        $this->usageService = $usageService;
    }

    /**
     * Get usage stats for authenticated user
     * GET /api/usage/stats
     */
    public function getStats(Request $request)
    {
        $stats = $this->usageService->getUsageStats($request->user()->id);

        if (!$stats) {
            return response()->json([
                'message' => 'No active subscription found',
                'stats' => null,
            ], 404);
        }

        return response()->json($stats);
    }

    /**
     * Check if user can perform an action
     * POST /api/usage/can-perform
     */
    public function canPerform(Request $request)
    {
        $request->validate([
            'action_type' => 'required|string',
        ]);

        $result = $this->usageService->canPerformAction(
            $request->user()->id,
            $request->action_type
        );

        return response()->json($result);
    }

    /**
     * Track an action
     * POST /api/usage/track
     * (Called internally after successful action)
     */
    public function track(Request $request)
    {
        $request->validate([
            'action_type' => 'required|string',
            'resource_type' => 'required|string',
            'resource_id' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $this->usageService->track(
            $request->user()->id,
            $request->action_type,
            $request->resource_type,
            $request->resource_id,
            $request->metadata
        );

        return response()->json([
            'message' => 'Action tracked successfully',
        ]);
    }
}