<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Carbon;

class Serializers
{
    public static function user(User $user): array
    {
        return array_merge(ApiId::dual($user->id), [
            'fullName' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'avatarUrl' => $user->avatar_url,
            'bio' => $user->bio ?? '',
            'emailVerified' => (bool) $user->email_verified,
            'emailVerifiedAt' => optional($user->email_verified_at)?->toISOString(),
            'deliveryAddress' => Validators::normalizeAddress($user->delivery_address),
            'pickupAddress' => Validators::normalizeAddress($user->pickup_address),
            'createdAt' => optional($user->created_at)?->toISOString(),
        ]);
    }

    public static function category(?Category $category): ?array
    {
        if (! $category) {
            return null;
        }

        return array_merge(ApiId::dual($category->id), [
            'name' => $category->name,
            'slug' => $category->slug,
            'icon' => $category->icon,
        ]);
    }

    public static function station(?Station $station): ?array
    {
        if (! $station) {
            return null;
        }

        return array_merge(ApiId::dual($station->id), [
            'city' => $station->city,
            'address' => $station->address,
            'lockerCode' => $station->locker_code,
            'partnerName' => $station->partner_name,
            'isActive' => (bool) $station->is_active,
        ]);
    }

    public static function sellerBrief(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $pickup = Validators::normalizeAddress($user->pickup_address ?? []);
        $locationParts = array_values(array_filter([
            $pickup['district'] !== '' ? $pickup['district'] : null,
            $pickup['city'] !== '' ? $pickup['city'] : null,
        ]));

        return array_merge(ApiId::dual($user->id), [
            'fullName' => $user->full_name,
            'name' => $user->full_name,
            'email' => $user->email,
            'avatarUrl' => $user->avatar_url,
            'avatar' => $user->avatar_url,
            'pickupCity' => $pickup['city'],
            'pickupDistrict' => $pickup['district'],
            'pickupLocation' => $locationParts !== [] ? implode(', ', $locationParts) : '',
        ]);
    }

    public static function product(Product $product): array
    {
        $product->loadMissing(['seller', 'category', 'station']);

        $category = $product->category;
        $attributes = is_array($product->attributes) ? $product->attributes : [];
        $seller = self::sellerBrief($product->seller);

        return array_merge(ApiId::dual($product->id), [
            'price' => (float) $product->price,
            'title' => $product->title,
            'brand' => $product->brand ?? '',
            'description' => $product->description,
            'condition' => $product->condition,
            'attributes' => $attributes,
            'attributeLabels' => CategorySchemas::labeledAttributes($category?->slug, $attributes),
            'images' => $product->images ?? [],
            'isVerified' => (bool) $product->is_verified,
            'moderationStatus' => $product->moderation_status,
            'rejectionReason' => $product->rejection_reason,
            'moderatedAt' => optional($product->moderated_at)?->toISOString(),
            'seller' => $seller,
            'category' => self::category($category),
            'station' => null,
            'pickupLocation' => $seller['pickupLocation'] ?? '',
            'status' => $product->status,
            'acceptsOffers' => (bool) $product->accepts_offers,
            'aiMeta' => $product->ai_meta,
            'createdAt' => optional($product->created_at)?->toISOString(),
            'updatedAt' => optional($product->updated_at)?->toISOString(),
        ]);
    }

    public static function offer(Offer $offer): array
    {
        $offer->expireIfNeeded();
        $offer->loadMissing(['product', 'buyer', 'seller']);

        $product = $offer->product;

        return array_merge(ApiId::dual($offer->id), [
            'product' => $product ? array_merge(ApiId::dual($product->id), [
                'title' => $product->title,
                'price' => (float) $product->price,
                'images' => $product->images ?? [],
                'status' => $product->status,
                'moderationStatus' => $product->moderation_status,
            ]) : null,
            'buyer' => self::sellerBrief($offer->buyer),
            'seller' => self::sellerBrief($offer->seller),
            'listPrice' => (float) $offer->list_price,
            'discountPercent' => (int) $offer->discount_percent,
            'offerPrice' => (float) $offer->offer_price,
            'message' => $offer->message,
            'status' => $offer->status,
            'expiresAt' => optional($offer->expires_at)?->toISOString(),
            'acceptedAt' => optional($offer->accepted_at)?->toISOString(),
            'rejectedAt' => optional($offer->rejected_at)?->toISOString(),
            'cancelledAt' => optional($offer->cancelled_at)?->toISOString(),
            'order' => $offer->order_id ? ApiId::dual($offer->order_id) : null,
            'createdAt' => optional($offer->created_at)?->toISOString(),
            'updatedAt' => optional($offer->updated_at)?->toISOString(),
        ]);
    }

    public static function order(Order $order): array
    {
        $order->loadMissing(['buyer', 'shipper', 'pickupStation', 'offer']);

        $items = collect($order->items ?? [])->map(function ($item) {
            $sellerId = $item['seller'] ?? $item['seller_id'] ?? null;
            $seller = is_array($sellerId) ? null : User::query()->find($sellerId);
            $productId = $item['product'] ?? $item['product_id'] ?? null;

            return [
                'product' => $productId ? ApiId::dual($productId) : null,
                'title' => $item['title'] ?? '',
                'price' => (float) ($item['price'] ?? 0),
                'image' => $item['image'] ?? '',
                'seller' => $seller
                    ? self::sellerBrief($seller)
                    : (is_array($item['seller'] ?? null) ? $item['seller'] : null),
            ];
        })->all();

        $timeline = collect($order->timeline ?? [])->map(function ($entry) {
            return [
                'status' => $entry['status'] ?? '',
                'note' => $entry['note'] ?? '',
                'at' => isset($entry['at'])
                    ? (Carbon::parse($entry['at'])->toISOString())
                    : null,
                'by' => isset($entry['by']) ? (string) $entry['by'] : null,
            ];
        })->all();

        return array_merge(ApiId::dual($order->id), [
            'buyer' => self::sellerBrief($order->buyer),
            'items' => $items,
            'totalAmount' => (float) $order->total_amount,
            'platformFee' => (float) $order->platform_fee,
            'sellerPayout' => (float) $order->seller_payout,
            'currency' => $order->currency,
            'note' => $order->note,
            'offer' => $order->offer_id ? ApiId::dual($order->offer_id) : null,
            'pickupStation' => self::station($order->pickupStation),
            'deliveryAddress' => Validators::normalizeAddress($order->delivery_address),
            'pickupAddress' => Validators::normalizeAddress($order->pickup_address),
            'status' => $order->normalizedStatus(),
            'paymentStatus' => $order->payment_status,
            'escrowStatus' => $order->escrow_status,
            'paypalOrderId' => $order->paypal_order_id,
            'shipper' => self::sellerBrief($order->shipper),
            'assignedAt' => optional($order->assigned_at)?->toISOString(),
            'estimatedDeliveryAt' => optional($order->estimated_delivery_at)?->toISOString(),
            'estimatedPickupAt' => optional($order->estimated_pickup_at)?->toISOString(),
            'pickedUpAt' => optional($order->picked_up_at)?->toISOString(),
            'deliveredAt' => optional($order->delivered_at)?->toISOString(),
            'buyerConfirmedAt' => optional($order->buyer_confirmed_at)?->toISOString(),
            'autoCompleteAt' => optional($order->auto_complete_at)?->toISOString(),
            'completedAt' => optional($order->completed_at)?->toISOString(),
            'cancelledAt' => optional($order->cancelled_at)?->toISOString(),
            'cancelReason' => $order->cancel_reason,
            'timeline' => $timeline,
            'dispute' => $order->dispute,
            'paidAt' => optional($order->paid_at)?->toISOString(),
            'createdAt' => optional($order->created_at)?->toISOString(),
            'updatedAt' => optional($order->updated_at)?->toISOString(),
        ]);
    }

    public static function notification(Notification $notification): array
    {
        return array_merge(ApiId::dual($notification->id), [
            'title' => $notification->title,
            'body' => $notification->body,
            'type' => $notification->type,
            'link' => $notification->link,
            'data' => $notification->data ?? new \stdClass,
            'readAt' => optional($notification->read_at)?->toISOString(),
            'createdAt' => optional($notification->created_at)?->toISOString(),
        ]);
    }
}
