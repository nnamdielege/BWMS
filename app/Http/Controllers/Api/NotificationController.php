<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    /**
     * Get all notifications for authenticated user
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $unreadOnly = $request->get('unread_only', false);

            $query = Notification::where('user_id', Auth::id())
                ->orderBy('created_at', 'desc');

            if ($unreadOnly) {
                $query->where('is_read', false);
            }

            $notifications = $query->paginate($perPage);

            return response()->json($notifications);
        } catch (\Exception $e) {
            Log::error('Fetch notifications error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch notifications',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get unread count
     */
    public function unreadCount()
    {
        try {
            $count = Notification::where('user_id', Auth::id())
                ->where('is_read', false)
                ->count();

            return response()->json(['count' => $count]);
        } catch (\Exception $e) {
            Log::error('Unread count error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to get unread count',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark notification as read
     */
    public function markAsRead($id)
    {
        try {
            $notification = Notification::where('user_id', Auth::id())
                ->findOrFail($id);

            $notification->markAsRead();

            return response()->json([
                'message' => 'Notification marked as read',
                'data' => $notification
            ]);
        } catch (\Exception $e) {
            Log::error('Mark as read error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to mark notification as read',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark all as read
     */
    public function markAllAsRead()
    {
        try {
            Notification::where('user_id', Auth::id())
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                ]);

            return response()->json([
                'message' => 'All notifications marked as read'
            ]);
        } catch (\Exception $e) {
            Log::error('Mark all as read error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to mark all notifications as read',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete notification
     */
    public function destroy($id)
    {
        try {
            $notification = Notification::where('user_id', Auth::id())
                ->findOrFail($id);

            $notification->delete();

            return response()->json([
                'message' => 'Notification deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Delete notification error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to delete notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}