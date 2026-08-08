<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Serializers;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(protected NotificationService $notifications) {}

    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $items = Notification::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (Notification $n) => Serializers::notification($n));

        $unreadCount = Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'notifications' => $items,
            'unreadCount' => $unreadCount,
        ]);
    }

    public function registerFcmToken(Request $request)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $token = trim((string) $request->input('token', ''));
        if ($token === '') {
            return response()->json(['message' => 'token is required.'], 400);
        }

        $tokens = $user->fcm_tokens ?? [];
        if (! in_array($token, $tokens, true)) {
            $tokens[] = $token;
            $user->fcm_tokens = $tokens;
            $user->save();
        }

        return response()->json([
            'message' => 'FCM token registered.',
            'firebaseReady' => $this->notifications->firebaseReady(),
        ]);
    }

    public function removeFcmToken(Request $request)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $token = trim((string) $request->input('token', ''));
        $user->fcm_tokens = array_values(array_filter(
            $user->fcm_tokens ?? [],
            fn ($t) => $t !== $token
        ));
        $user->save();

        return response()->json(['message' => 'FCM token removed.']);
    }

    public function readAll(Request $request)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    public function readOne(Request $request, string $id)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $notification = Notification::query()
            ->where('user_id', $user->id)
            ->whereKey($id)
            ->first();
        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }
        if (! $notification->read_at) {
            $notification->read_at = now();
            $notification->save();
        }

        return response()->json([
            'notification' => Serializers::notification($notification),
        ]);
    }

    public function test(Request $request)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $notification = $this->notifications->createAndPush(
            $user,
            (string) $request->input('title', 'Test notification'),
            (string) $request->input('body', 'This is a test push from ReBox.'),
            'test'
        );

        return response()->json([
            'message' => 'Test notification sent.',
            'notification' => Serializers::notification($notification),
        ], 201);
    }

    public function cart(Request $request)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $title = trim((string) $request->input('productTitle', ''));
        if ($title === '') {
            return response()->json(['message' => 'productTitle is required.'], 400);
        }

        $notification = $this->notifications->createAndPush(
            $user,
            'Added to cart',
            "{$title} was added to your cart.",
            'system',
            '/order',
            [
                'productId' => (string) $request->input('productId', ''),
                'productTitle' => $title,
            ]
        );

        return response()->json([
            'message' => 'Cart notification sent.',
            'notification' => Serializers::notification($notification),
        ], 201);
    }
}
