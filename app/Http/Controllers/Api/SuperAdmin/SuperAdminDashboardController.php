<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Subscription;
use App\Models\UsageRecord;
use App\Models\UsageResetLog;
use App\Models\UsageTracking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SuperAdminDashboardController extends Controller
{
    /**
     * Get dashboard overview with key metrics
     */
    public function getOverview()
    {
        $totalUsers = User::count();
        $activeUsers = User::where('is_active', true)->count();
        $inactiveUsers = User::where('is_active', false)->count();

        // Subscription metrics
        $activeSubscriptions = Subscription::where('status', 'active')->count();
        $trialSubscriptions = Subscription::where('status', 'trial')->count();
        $cancelledSubscriptions = Subscription::where('status', 'cancelled')->count();
        $suspendedSubscriptions = Subscription::where('status', 'suspended')->count();

        // Revenue metrics
        $totalRevenue = Subscription::where('status', 'active')
            ->with('plan')
            ->get()
            ->sum(function ($subscription) {
                return $subscription->plan->amount ?? 0;
            });

        $monthlyRecurringRevenue = $totalRevenue;

        $churnRate = $this->calculateChurnRate();

        // Plan breakdown
        $planBreakdown = Subscription::with('plan')
            ->where('status', 'active')
            ->get()
            ->groupBy('plan.name')
            ->map(function ($group) {
                return [
                    'name' => $group->first()->plan->name,
                    'count' => $group->count(),
                    'revenue' => $group->sum(function ($s) {
                        return $s->plan->amount ?? 0;
                    }),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'users' => [
                    'total' => $totalUsers,
                    'active' => $activeUsers,
                    'inactive' => $inactiveUsers,
                ],
                'subscriptions' => [
                    'active' => $activeSubscriptions,
                    'trial' => $trialSubscriptions,
                    'cancelled' => $cancelledSubscriptions,
                    'suspended' => $suspendedSubscriptions,
                    'total' => $activeSubscriptions + $trialSubscriptions + $cancelledSubscriptions + $suspendedSubscriptions,
                ],
                'revenue' => [
                    'total' => $totalRevenue,
                    'mrr' => $monthlyRecurringRevenue,
                    'churn_rate' => round($churnRate, 2),
                ],
                'plan_breakdown' => $planBreakdown,
            ],
        ]);
    }

    /**
     * Get all subscriptions with filters and pagination
     */
    public function getSubscriptions(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);
        $status = $request->get('status'); // active, trial, cancelled, suspended
        $plan = $request->get('plan');
        $search = $request->get('search'); // search by user email or name

        $query = Subscription::with(['user', 'plan'])
            ->orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        if ($plan) {
            $query->whereHas('plan', function ($q) use ($plan) {
                $q->where('id', $plan);
            });
        }

        if ($search) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $subscriptions = $query->paginate($perPage, ['*'], 'page', $page);

        $formatted = $subscriptions->map(function ($subscription) {
            return [
                'id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'user_name' => $subscription->user->name,
                'user_email' => $subscription->user->email,
                'plan_name' => $subscription->plan->name ?? 'N/A',
                'status' => $subscription->status,
                'amount' => $subscription->plan->amount ?? 0,
                'interval' => $subscription->plan->interval ?? 'month',
                'current_period_start' => $subscription->current_period_start,
                'current_period_end' => $subscription->current_period_end,
                'trial_ends_at' => $subscription->trial_ends_at,
                'paused_at' => $subscription->paused_at,
                'user_is_active' => $subscription->user->is_active,
                'created_at' => $subscription->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
            'pagination' => [
                'total' => $subscriptions->total(),
                'per_page' => $subscriptions->perPage(),
                'current_page' => $subscriptions->currentPage(),
                'last_page' => $subscriptions->lastPage(),
            ],
        ]);
    }

    /**
     * Get all users with their subscription details
     */
    public function getUsers(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);
        $status = $request->get('status'); // active, inactive
        $search = $request->get('search');

        $query = User::with('subscription')
            ->orderBy('created_at', 'desc');

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if ($search) {
            $query->where('email', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%");
        }

        $users = $query->paginate($perPage, ['*'], 'page', $page);

        $formatted = $users->map(function ($user) {
            $subscription = $user->subscription;

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'created_at' => $user->created_at,
                'subscription' => $subscription ? [
                    'id' => $subscription->id,
                    'plan_name' => $subscription->plan->name ?? 'N/A',
                    'status' => $subscription->status,
                    'amount' => $subscription->plan->amount ?? 0,
                    'current_period_end' => $subscription->current_period_end,
                    'trial_ends_at' => $subscription->trial_ends_at,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
            'pagination' => [
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    /**
     * Get detailed user information
     */
    public function getUserDetail($userId)
    {
        $user = User::with(['subscription.plan', 'roles'])->findOrFail($userId);

        // Get user's usage stats from UsageRecord
        $usageStats = $this->getUserUsageStats($user);

        // Get user's activity log from UsageTracking
        $activityLog = UsageTracking::where('user_id', $userId)
            ->orderBy('tracked_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($tracking) {
                return [
                    'id' => $tracking->id,
                    'action_type' => $tracking->action_type,
                    'resource_type' => $tracking->resource_type,
                    'resource_id' => $tracking->resource_id,
                    'metadata' => $tracking->metadata,
                    'tracked_at' => $tracking->tracked_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'created_at' => $user->created_at,
                'roles' => $user->roles->pluck('name'),
                'subscription' => $user->subscription ? [
                    'id' => $user->subscription->id,
                    'plan' => [
                        'name' => $user->subscription->plan->name ?? 'N/A',
                        'amount' => $user->subscription->plan->amount ?? 0,
                        'interval' => $user->subscription->plan->interval ?? 'month',
                        'features' => $user->subscription->plan->features,
                    ],
                    'status' => $user->subscription->status,
                    'current_period_start' => $user->subscription->current_period_start,
                    'current_period_end' => $user->subscription->current_period_end,
                    'trial_ends_at' => $user->subscription->trial_ends_at,
                    'paused_at' => $user->subscription->paused_at,
                ] : null,
                'usage_stats' => $usageStats,
                'activity_log' => $activityLog,
            ],
        ]);
    }

    /**
     * Toggle user active/inactive status
     */
    public function toggleUserStatus($userId)
    {
        $user = User::findOrFail($userId);
        $oldStatus = $user->is_active;

        $user->is_active = !$user->is_active;
        $user->save();

        // Log the change to UsageTracking for audit trail
        UsageTracking::create([
            'user_id' => auth()->id() ?? $userId, // Admin performing action
            'subscription_id' => $user->subscription_id,
            'action_type' => 'user_status_changed',
            'resource_type' => 'User',
            'resource_id' => $user->id,
            'metadata' => [
                'old_status' => $oldStatus,
                'new_status' => $user->is_active,
                'target_user_id' => $user->id,
                'target_user_email' => $user->email,
            ],
            'tracked_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User status updated successfully',
            'data' => [
                'id' => $user->id,
                'is_active' => $user->is_active,
            ],
        ]);
    }

    /**
     * Suspend/Resume subscription
     */
    public function toggleSubscriptionStatus($subscriptionId, Request $request)
    {
        $subscription = Subscription::findOrFail($subscriptionId);
        $action = $request->get('action'); // suspend or resume
        $oldStatus = $subscription->status;

        if ($action === 'suspend') {
            $subscription->status = 'suspended';
            $subscription->paused_at = now();
            $message = 'Subscription suspended successfully';
        } elseif ($action === 'resume') {
            $subscription->status = 'active';
            $subscription->paused_at = null;
            $message = 'Subscription resumed successfully';
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Invalid action. Use suspend or resume',
            ], 400);
        }

        $subscription->save();

        // Log the change to UsageTracking for audit trail
        UsageTracking::create([
            'user_id' => auth()->id(),
            'subscription_id' => $subscription->id,
            'action_type' => 'subscription_' . $action,
            'resource_type' => 'Subscription',
            'resource_id' => $subscription->id,
            'metadata' => [
                'old_status' => $oldStatus,
                'new_status' => $subscription->status,
                'user_id' => $subscription->user_id,
                'paused_at' => $subscription->paused_at,
            ],
            'tracked_at' => now(),
        ]);

        // Send notification to user
        $this->notifySubscriptionStatusChange($subscription, $action);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'paused_at' => $subscription->paused_at,
            ],
        ]);
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription($subscriptionId, Request $request)
    {
        $subscription = Subscription::findOrFail($subscriptionId);
        $reason = $request->get('reason', 'Admin cancelled');
        $oldStatus = $subscription->status;

        $subscription->status = 'cancelled';
        $subscription->cancelled_at = now();
        $subscription->cancellation_reason = $reason;
        $subscription->save();

        // Log the cancellation to UsageTracking for audit trail
        UsageTracking::create([
            'user_id' => auth()->id(),
            'subscription_id' => $subscription->id,
            'action_type' => 'subscription_cancelled',
            'resource_type' => 'Subscription',
            'resource_id' => $subscription->id,
            'metadata' => [
                'old_status' => $oldStatus,
                'new_status' => $subscription->status,
                'reason' => $reason,
                'user_id' => $subscription->user_id,
                'cancelled_at' => $subscription->cancelled_at,
            ],
            'tracked_at' => now(),
        ]);

        // Send notification
        $this->notifySubscriptionCancellation($subscription, $reason);

        return response()->json([
            'success' => true,
            'message' => 'Subscription cancelled successfully',
            'data' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'cancelled_at' => $subscription->cancelled_at,
            ],
        ]);
    }

    /**
     * Get revenue analytics
     */
    public function getRevenueAnalytics(Request $request)
    {
        $days = $request->get('days', 30);
        $startDate = now()->subDays($days);

        // Daily revenue
        $dailyRevenue = Subscription::where('created_at', '>=', $startDate)
            ->where('status', 'active')
            ->with('plan')
            ->get()
            ->groupBy(function ($subscription) {
                return $subscription->created_at->format('Y-m-d');
            })
            ->map(function ($group) {
                return [
                    'date' => $group->first()->created_at->format('Y-m-d'),
                    'revenue' => $group->sum(function ($s) {
                        return $s->plan->amount ?? 0;
                    }),
                    'count' => $group->count(),
                ];
            })
            ->values();

        // Plan comparison
        $planComparison = Subscription::with('plan')
            ->where('created_at', '>=', $startDate)
            ->where('status', 'active')
            ->get()
            ->groupBy('plan.name')
            ->map(function ($group) {
                return [
                    'plan' => $group->first()->plan->name,
                    'count' => $group->count(),
                    'revenue' => $group->sum(function ($s) {
                        return $s->plan->amount ?? 0;
                    }),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'daily_revenue' => $dailyRevenue,
                'plan_comparison' => $planComparison,
            ],
        ]);
    }

    /**
     * Get audit logs from UsageTracking
     */
    public function getAuditLogs(Request $request)
    {
        $perPage = $request->get('per_page', 20);
        $page = $request->get('page', 1);
        $action = $request->get('action'); // filter by action type

        $query = UsageTracking::query()
            ->orderBy('tracked_at', 'desc');

        if ($action) {
            $query->where('action_type', $action);
        }

        $logs = $query->paginate($perPage, ['*'], 'page', $page);

        $formatted = $logs->map(function ($log) {
            return [
                'id' => $log->id,
                'user_id' => $log->user_id,
                'subscription_id' => $log->subscription_id,
                'action_type' => $log->action_type,
                'resource_type' => $log->resource_type,
                'resource_id' => $log->resource_id,
                'metadata' => $log->metadata,
                'tracked_at' => $log->tracked_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
            'pagination' => [
                'total' => $logs->total(),
                'per_page' => $logs->perPage(),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    /**
     * Export subscriptions to CSV
     */
    public function exportSubscriptions(Request $request)
    {
        $subscriptions = Subscription::with(['user', 'plan'])
            ->get();

        $csv = "User Name,Email,Plan,Status,Amount,Start Date,End Date\n";

        foreach ($subscriptions as $subscription) {
            $csv .= implode(',', [
                $subscription->user->name,
                $subscription->user->email,
                $subscription->plan->name ?? 'N/A',
                $subscription->status,
                $subscription->plan->amount ?? 0,
                $subscription->current_period_start,
                $subscription->current_period_end,
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="subscriptions.csv"',
        ]);
    }

    /**
     * Helper: Calculate churn rate
     */
    private function calculateChurnRate()
    {
        $thirtyDaysAgo = now()->subDays(30);

        $startingSubscriptions = Subscription::where('created_at', '<', $thirtyDaysAgo)
            ->where('status', '!=', 'cancelled')
            ->count();

        $cancelledInPeriod = Subscription::where('cancelled_at', '>=', $thirtyDaysAgo)
            ->count();

        if ($startingSubscriptions === 0) {
            return 0;
        }

        return ($cancelledInPeriod / $startingSubscriptions) * 100;
    }

    /**
     * Helper: Get user usage stats from UsageRecord
     */
    private function getUserUsageStats($user)
    {
        if (!$user->subscription) {
            return [];
        }

        $subscription = $user->subscription;
        $plan = $subscription->plan;

        // Get the current usage records for the user
        $currentUsage = UsageRecord::where('user_id', $user->id)
            ->where('subscription_id', $subscription->id)
            ->where('recorded_date', '>=', $subscription->current_period_start)
            ->where('recorded_date', '<=', $subscription->current_period_end)
            ->orderBy('recorded_date', 'desc')
            ->first();

        if (!$currentUsage) {
            return [];
        }

        // Get plan features from the plan model
        $features = is_string($plan->features)
            ? json_decode($plan->features, true)
            : $plan->features;

        if (!is_array($features)) {
            $features = [];
        }

        $stats = [];

        // Map usage record fields to features
        if (isset($features['data_storage_mb'])) {
            $stats[] = [
                'feature' => 'data_storage_mb',
                'label' => 'Data Storage (MB)',
                'limit' => $features['data_storage_mb'],
                'used' => $currentUsage->data_used_mb ?? 0,
                'remaining' => ($features['data_storage_mb'] - ($currentUsage->data_used_mb ?? 0)),
                'percentage' => $features['data_storage_mb'] > 0
                    ? (($currentUsage->data_used_mb ?? 0) / $features['data_storage_mb']) * 100
                    : 0,
            ];
        }

        if (isset($features['api_calls'])) {
            $stats[] = [
                'feature' => 'api_calls',
                'label' => 'API Calls',
                'limit' => $features['api_calls'],
                'used' => $currentUsage->api_calls ?? 0,
                'remaining' => ($features['api_calls'] - ($currentUsage->api_calls ?? 0)),
                'percentage' => $features['api_calls'] > 0
                    ? (($currentUsage->api_calls ?? 0) / $features['api_calls']) * 100
                    : 0,
            ];
        }

        if (isset($features['items_tracked'])) {
            $stats[] = [
                'feature' => 'items_tracked',
                'label' => 'Items Tracked',
                'limit' => $features['items_tracked'],
                'used' => $currentUsage->items_tracked ?? 0,
                'remaining' => ($features['items_tracked'] - ($currentUsage->items_tracked ?? 0)),
                'percentage' => $features['items_tracked'] > 0
                    ? (($currentUsage->items_tracked ?? 0) / $features['items_tracked']) * 100
                    : 0,
            ];
        }

        return $stats;
    }

    /**
     * Helper: Notify user of subscription status change
     */
    private function notifySubscriptionStatusChange($subscription, $action)
    {
        // Send email notification
        // You can implement mailing logic here
        // Example: Mail::send(new SubscriptionStatusNotification($subscription, $action));
    }

    /**
     * Helper: Notify user of subscription cancellation
     */
    private function notifySubscriptionCancellation($subscription, $reason)
    {
        // Send email notification
        // You can implement mailing logic here
        // Example: Mail::send(new SubscriptionCancelledNotification($subscription, $reason));
    }
}