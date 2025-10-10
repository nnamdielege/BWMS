<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    /**
     * Get current user profile
     */
    public function show()
    {
        try {
            $user = Auth::user();

            return response()->json([
                'data' => $user
            ]);
        } catch (\Exception $e) {
            Log::error('Profile fetch error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user profile
     */
    public function update(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:50',
        ]);

        try {
            $user->update($validated);

            return response()->json([
                'message' => 'Profile updated successfully',
                'data' => $user
            ]);
        } catch (\Exception $e) {
            Log::error('Profile update error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to update profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user password
     */
    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = Auth::user();

        // Verify current password
        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect',
                'errors' => [
                    'current_password' => ['The current password is incorrect']
                ]
            ], 422);
        }

        try {
            $user->update([
                'password' => Hash::make($validated['new_password'])
            ]);

            return response()->json([
                'message' => 'Password updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Password update error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to update password',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload avatar
     */
    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $user = Auth::user();

        try {
            // Delete old avatar if exists
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            // Store new avatar
            $path = $request->file('avatar')->store('avatars', 'public');

            $user->update([
                'avatar' => $path
            ]);

            return response()->json([
                'message' => 'Avatar uploaded successfully',
                'data' => [
                    'avatar' => $path,
                    'avatar_url' => Storage::disk('public')->url($path)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Avatar upload error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to upload avatar',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete avatar
     */
    public function deleteAvatar()
    {
        $user = Auth::user();

        try {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            $user->update([
                'avatar' => null
            ]);

            return response()->json([
                'message' => 'Avatar deleted successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error('Avatar deletion error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to delete avatar',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}