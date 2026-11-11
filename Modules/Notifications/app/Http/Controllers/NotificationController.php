<?php

namespace Modules\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Notifications\Services\NotificationService;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->notifications->list($user), 'unread' => $this->notifications->unreadCount($user)]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->notifications->unreadCount($request->user())]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $n = $this->notifications->markRead($user, $id);

        return response()->json(['data' => NotificationService::present($n, $user), 'unread' => $this->notifications->unreadCount($user)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $count = $this->notifications->markAll($request->user());

        return response()->json(['count' => $count, 'unread' => 0, 'message' => 'تم تحديد الكل كمقروء']);
    }
}
