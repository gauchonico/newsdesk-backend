<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user's in-app notifications.
 */
class NotificationsController extends Controller
{
    /**
     * GET /api/notifications — latest 30 plus the unread count
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $user->notifications()->latest()->limit(30)->get()->map(fn ($n) => [
                'id'     => $n->id,
                'read'   => $n->read_at !== null,
                'at'     => $n->created_at->toIso8601String(),
                ...$n->data,
            ]),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * POST /api/notifications/{id}/read
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->where('id', $id)->update(['read_at' => now()]);

        return response()->json(['message' => 'Marked as read.']);
    }

    /**
     * POST /api/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'All marked as read.']);
    }
}
