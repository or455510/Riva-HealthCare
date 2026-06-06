<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    // ✅ الحصول على جميع الإشعارات
    public function index(Request $request)
    {
        $user = $request->user();
        $notifications = $this->notificationService->getUserNotifications(
            $user,
            (int) $request->integer('per_page', 20)
        );

        return response()->json([
            'notifications' => collect($notifications->items())
                ->map(fn (Notification $notification) => $this->serializeNotification($notification))
                ->values(),
            'total' => $notifications->total(),
            'unread_count' => $user->notifications()->whereNull('read_at')->count(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
            ]
        ]);
    }

    // ✅ الحصول على إشعار واحد
    public function show(Request $request, Notification $notification)
    {
        if ($notification->notifiable_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->markAsRead();

        return response()->json([
            'data' => $this->serializeNotification($notification->fresh()),
        ]);
    }

    // ✅ تعديل إشعار كمقروء
    public function markAsRead(Request $request, Notification $notification)
    {
        if ($notification->notifiable_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->markAsRead();

        return response()->json(['message' => 'Marked as read']);
    }

    // ✅ تعديل جميع الإشعارات كمقروءة
    public function markAllAsRead(Request $request)
    {
        $this->notificationService->markAllAsRead($request->user());

        return response()->json(['message' => 'All marked as read']);
    }

    // ✅ حذف إشعار
    public function destroy(Request $request, Notification $notification)
    {
        if ($notification->notifiable_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->delete();

        return response()->json(['message' => 'Notification deleted']);
    }

    // ✅ حذف جميع الإشعارات
    public function destroyAll(Request $request)
    {
        $request->user()->notifications()->delete();

        return response()->json(['message' => 'All notifications deleted']);
    }

    // ✅ الحصول على عدد الإشعارات غير المقروءة
    public function unreadCount(Request $request)
    {
        $count = $request->user()->notifications()->whereNull('read_at')->count();

        return response()->json(['unread_count' => $count]);
    }

    public function read(Request $request, Notification $notification)
    {
        return $this->markAsRead($request, $notification);
    }

    public function readAll(Request $request)
    {
        return $this->markAllAsRead($request);
    }

    private function serializeNotification(Notification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $message = $notification->message
            ?: ($data['message'] ?? $data['body'] ?? $data['content'] ?? '');
        $title = $notification->title ?: ($data['title'] ?? 'Notification');
        $category = $notification->category ?: ($data['category'] ?? $notification->type ?? 'general');
        $actionUrl = $notification->action_url ?: ($data['action_url'] ?? $data['url'] ?? null);

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $title,
            'message' => $message,
            'body' => $message,
            'category' => $category,
            'data' => $data,
            'action_url' => $actionUrl,
            'related_type' => $data['related_type'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'read_at' => optional($notification->read_at)->toISOString(),
            'created_at' => optional($notification->created_at)->toISOString(),
            'date' => optional($notification->created_at)->toISOString(),
        ];
    }
}
