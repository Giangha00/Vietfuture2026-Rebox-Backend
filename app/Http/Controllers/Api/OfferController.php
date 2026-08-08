<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\NotificationService;
use App\Support\Money;
use App\Support\Serializers;
use Illuminate\Http\Request;

class OfferController extends Controller
{
    public function __construct(
        protected NotificationService $notifications,
        protected AdminNotificationService $adminNotifications,
    ) {}

    public function options()
    {
        return response()->json([
            'allowedPercents' => Offer::PERCENTS,
            'ttlHours' => (int) config('rebox.offer_ttl_hours', 48),
        ]);
    }

    public function store(Request $request)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $productId = $request->input('productId');
        $discountPercent = (int) $request->input('discountPercent');
        $message = mb_substr(trim((string) $request->input('message', '')), 0, 300);

        if (! in_array($discountPercent, Offer::PERCENTS, true)) {
            return response()->json(['message' => 'Discount percent must be 5, 10, or 15.'], 400);
        }

        $product = Product::query()->find($productId);
        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }
        if ($product->moderation_status !== 'approved' || $product->status !== 'active') {
            return response()->json(['message' => 'Product is not available for offers.'], 400);
        }
        if (! $product->accepts_offers) {
            return response()->json(['message' => 'This listing does not accept offers.'], 400);
        }
        if ((int) $product->seller_id === (int) $user->id) {
            return response()->json(['message' => 'You cannot offer on your own listing.'], 400);
        }

        $pending = Offer::query()
            ->where('product_id', $product->id)
            ->where('buyer_id', $user->id)
            ->where('status', 'pending')
            ->first();
        if ($pending) {
            return response()->json([
                'message' => 'You already have a pending offer on this product.',
                'offer' => Serializers::offer($pending),
            ], 409);
        }

        $accepted = Offer::query()
            ->where('product_id', $product->id)
            ->where('buyer_id', $user->id)
            ->where('status', 'accepted')
            ->whereNull('order_id')
            ->first();
        if ($accepted) {
            return response()->json([
                'message' => 'You already have an accepted offer on this product.',
                'offer' => Serializers::offer($accepted),
            ], 409);
        }

        $listPrice = (float) $product->price;
        $offer = Offer::query()->create([
            'product_id' => $product->id,
            'buyer_id' => $user->id,
            'seller_id' => $product->seller_id,
            'list_price' => $listPrice,
            'discount_percent' => $discountPercent,
            'offer_price' => Offer::calcOfferPrice($listPrice, $discountPercent),
            'message' => $message,
            'status' => 'pending',
            'expires_at' => now()->addHours((int) config('rebox.offer_ttl_hours', 48)),
        ]);

        $this->notifications->createAndPush(
            (int) $product->seller_id,
            'New offer received',
            "{$user->full_name} offered ".Money::format((float) $offer->offer_price)." on \"{$product->title}\".",
            'offer',
            '/offers/selling',
            ['offerId' => (string) $offer->id, 'productId' => (string) $product->id]
        );

        $this->adminNotifications->offerCreated($offer, (string) $product->title);

        return response()->json([
            'message' => 'Offer sent.',
            'offer' => Serializers::offer($offer),
        ], 201);
    }

    public function mine(Request $request)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $offers = Offer::query()
            ->with(['product', 'buyer', 'seller'])
            ->where('buyer_id', $user->id)
            ->latest()
            ->get()
            ->map(fn (Offer $o) => Serializers::offer($o));

        return response()->json([
            'offers' => $offers,
            'allowedPercents' => Offer::PERCENTS,
        ]);
    }

    public function selling(Request $request)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $offers = Offer::query()
            ->with(['product', 'buyer', 'seller'])
            ->where('seller_id', $user->id)
            ->latest()
            ->get()
            ->map(fn (Offer $o) => Serializers::offer($o));

        return response()->json([
            'offers' => $offers,
            'allowedPercents' => Offer::PERCENTS,
        ]);
    }

    public function show(Request $request, string $id)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $offer = Offer::query()->with(['product', 'buyer', 'seller'])->find($id);
        if (! $offer) {
            return response()->json(['message' => 'Offer not found.'], 404);
        }
        if (! in_array($user->role, ['admin'], true)
            && (int) $user->id !== (int) $offer->buyer_id
            && (int) $user->id !== (int) $offer->seller_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['offer' => Serializers::offer($offer)]);
    }

    public function accept(Request $request, string $id)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $offer = Offer::query()->with('product')->find($id);
        if (! $offer) {
            return response()->json(['message' => 'Offer not found.'], 404);
        }
        $offer->expireIfNeeded();
        if ($user->role !== 'admin' && (int) $user->id !== (int) $offer->seller_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($offer->status !== 'pending') {
            return response()->json(['message' => 'Only pending offers can be accepted.'], 400);
        }
        $product = $offer->product;
        if (! $product || $product->moderation_status !== 'approved' || $product->status !== 'active') {
            return response()->json(['message' => 'Product is no longer available.'], 400);
        }

        $offer->status = 'accepted';
        $offer->accepted_at = now();
        $offer->save();

        $this->notifications->createAndPush(
            (int) $offer->buyer_id,
            'Offer accepted',
            "Your offer on \"{$product->title}\" was accepted.",
            'offer',
            '/offers',
            ['offerId' => (string) $offer->id]
        );

        return response()->json([
            'message' => 'Offer accepted.',
            'offer' => Serializers::offer($offer->fresh(['product', 'buyer', 'seller'])),
        ]);
    }

    public function reject(Request $request, string $id)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $offer = Offer::query()->with('product')->find($id);
        if (! $offer) {
            return response()->json(['message' => 'Offer not found.'], 404);
        }
        $offer->expireIfNeeded();
        if ($user->role !== 'admin' && (int) $user->id !== (int) $offer->seller_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($offer->status !== 'pending') {
            return response()->json(['message' => 'Only pending offers can be rejected.'], 400);
        }

        if ($request->filled('reason')) {
            $offer->message = mb_substr(trim((string) $request->input('reason')), 0, 300);
        }
        $offer->status = 'rejected';
        $offer->rejected_at = now();
        $offer->save();

        $this->notifications->createAndPush(
            (int) $offer->buyer_id,
            'Offer rejected',
            'Your offer was rejected by the seller.',
            'offer',
            '/offers',
            ['offerId' => (string) $offer->id]
        );

        return response()->json([
            'message' => 'Offer rejected.',
            'offer' => Serializers::offer($offer->fresh(['product', 'buyer', 'seller'])),
        ]);
    }

    public function cancel(Request $request, string $id)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $offer = Offer::query()->with('product')->find($id);
        if (! $offer) {
            return response()->json(['message' => 'Offer not found.'], 404);
        }
        if ($user->role !== 'admin' && (int) $user->id !== (int) $offer->buyer_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if (! in_array($offer->status, ['pending', 'accepted'], true)) {
            return response()->json(['message' => 'Offer cannot be cancelled.'], 400);
        }

        $wasAccepted = $offer->status === 'accepted';
        $offer->status = 'cancelled';
        $offer->cancelled_at = now();
        $offer->save();

        if ($wasAccepted && ! $offer->order_id && $offer->product && $offer->product->status === 'reserved') {
            $offer->product->status = 'active';
            $offer->product->save();
        }

        $this->notifications->createAndPush(
            (int) $offer->seller_id,
            'Offer cancelled',
            'A buyer cancelled their offer.',
            'offer',
            '/offers/selling',
            ['offerId' => (string) $offer->id]
        );

        return response()->json([
            'message' => 'Offer cancelled.',
            'offer' => Serializers::offer($offer->fresh(['product', 'buyer', 'seller'])),
        ]);
    }
}
