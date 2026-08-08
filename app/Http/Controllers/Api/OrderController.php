<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\NotificationService;
use App\Services\OrderService;
use App\Services\PaypalService;
use App\Support\Money;
use App\Support\Serializers;
use App\Support\Validators;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orders,
        protected PaypalService $paypal,
        protected NotificationService $notifications,
        protected AdminNotificationService $adminNotifications,
    ) {}

    public function stream(Request $request)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $userId = (string) $user->id;
        $receiveAll = in_array($user->role, ['admin', 'shipper'], true);

        return response()->stream(function () use ($userId, $receiveAll) {
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', 'off');
            @ini_set('implicit_flush', '1');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ob_implicit_flush(true);
            set_time_limit(0);

            $this->writeSse('connected', [
                'ok' => true,
                'userId' => $userId,
                'receiveAll' => $receiveAll,
                'at' => now()->toISOString(),
            ]);

            $queue = [];
            $active = true;

            $listener = function ($payload) use (&$queue, &$active, $userId, $receiveAll) {
                if (! $active) {
                    return;
                }
                if (! is_array($payload)) {
                    return;
                }
                if (
                    ! $receiveAll
                    && ! in_array($userId, array_map('strval', $payload['participants'] ?? []), true)
                ) {
                    return;
                }
                $queue[] = $payload;
            };

            Event::listen('order.updated', $listener);

            $lastHeartbeat = time();
            try {
                while (! connection_aborted()) {
                    while ($queue !== []) {
                        $payload = array_shift($queue);
                        $this->writeSse('order.updated', $payload);
                    }

                    if (time() - $lastHeartbeat >= 15) {
                        echo ': ping '.(int) (microtime(true) * 1000)."\n\n";
                        $this->flushSse();
                        $lastHeartbeat = time();
                    }

                    usleep(200000);
                }
            } finally {
                $active = false;
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function store(Request $request)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            $offerId = $request->input('offerId');
            $note = trim((string) $request->input('note', ''));

            $rawPhone = $request->input('deliveryAddress.phone');
            $phoneDigits = Validators::normalizePhone($rawPhone);
            if ($phoneDigits === '') {
                return response()->json(['message' => 'Delivery phone is required (10 digits).'], 400);
            }
            if (! Validators::isValidPhone($phoneDigits, true)) {
                return response()->json(['message' => 'Phone number must be exactly 10 digits.'], 400);
            }

            $deliveryAddress = Validators::normalizeAddress($request->input('deliveryAddress') ?: []);
            $deliveryAddress['phone'] = $phoneDigits;
            $pickupAddress = Validators::normalizeAddress($request->input('pickupAddress') ?: []);

            if ($deliveryAddress['line1'] === '' || $deliveryAddress['city'] === '') {
                return response()->json([
                    'message' => 'Delivery address requires phone, line1, and city.',
                ], 400);
            }

            if ($deliveryAddress['fullName'] === '') {
                $deliveryAddress['fullName'] = (string) ($user->full_name ?? '');
            }

            $products = collect();
            $items = [];
            $linkedOffer = null;
            $productIds = [];

            if ($offerId) {
                $linkedOffer = Offer::query()->find($offerId);
                if (! $linkedOffer) {
                    return response()->json(['message' => 'Offer not found.'], 404);
                }
                $linkedOffer->expireIfNeeded();
                $linkedOffer->refresh();

                if ((int) $linkedOffer->buyer_id !== (int) $user->id) {
                    return response()->json(['message' => 'This offer does not belong to you.'], 403);
                }
                if ($linkedOffer->status !== 'accepted') {
                    return response()->json([
                        'message' => 'Only an accepted offer can be checked out.',
                    ], 400);
                }
                if ($linkedOffer->order_id) {
                    return response()->json([
                        'message' => 'This offer already has an order.',
                    ], 400);
                }

                $product = Product::query()->with('seller')->find($linkedOffer->product_id);
                if (
                    ! $product
                    || $product->moderation_status !== 'approved'
                    || $product->status !== 'active'
                ) {
                    return response()->json([
                        'message' => 'Product is unavailable for this offer (sold, reserved by another buyer, or inactive).',
                    ], 400);
                }

                $products = collect([$product]);
                $productIds = [$product->id];
                $items = [[
                    'product' => $product->id,
                    'title' => $product->title,
                    'price' => (float) $linkedOffer->offer_price,
                    'image' => is_array($product->images) ? (string) ($product->images[0] ?? '') : '',
                    'seller' => $product->seller_id,
                ]];
            } else {
                $productIds = collect($request->input('productIds', []))
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($productIds === []) {
                    return response()->json(['message' => 'Select at least one product.'], 400);
                }

                $products = Product::query()
                    ->with('seller')
                    ->whereIn('id', $productIds)
                    ->where('moderation_status', 'approved')
                    ->where('status', 'active')
                    ->get();

                if ($products->count() !== count($productIds)) {
                    return response()->json([
                        'message' => 'One or more products are unavailable (not approved, sold, or missing).',
                    ], 400);
                }

                foreach ($products as $product) {
                    if ((int) $product->seller_id === (int) $user->id) {
                        return response()->json([
                            'message' => 'You cannot buy your own listing.',
                        ], 400);
                    }
                }

                $items = $products->map(fn (Product $product) => [
                    'product' => $product->id,
                    'title' => $product->title,
                    'price' => (float) $product->price,
                    'image' => is_array($product->images) ? (string) ($product->images[0] ?? '') : '',
                    'seller' => $product->seller_id,
                ])->all();
            }

            $totalAmount = collect($items)->sum(fn ($item) => (float) ($item['price'] ?? 0));
            $fees = Money::calcFees((float) $totalAmount);

            $resolvedPickup = $pickupAddress;
            if ($resolvedPickup['line1'] === '') {
                $seller = $products->first()?->seller;
                $fromSeller = Validators::normalizeAddress($seller?->pickup_address);
                $resolvedPickup = Validators::normalizeAddress([
                    'fullName' => $fromSeller['fullName'] !== ''
                        ? $fromSeller['fullName']
                        : (string) ($seller?->full_name ?? ''),
                    'phone' => $fromSeller['phone'] !== ''
                        ? $fromSeller['phone']
                        : (string) ($seller?->phone ?? ''),
                    'line1' => $fromSeller['line1'],
                    'line2' => $fromSeller['line2'],
                    'city' => $fromSeller['city'],
                    'district' => $fromSeller['district'],
                    'note' => $fromSeller['note'],
                ]);
            }

            if ($resolvedPickup['line1'] === '' || $resolvedPickup['city'] === '') {
                return response()->json([
                    'message' => 'Seller has no pickup address. They must set where the courier can collect the item.',
                ], 400);
            }

            $timelineNote = $linkedOffer
                ? 'Order from accepted −'.$linkedOffer->discount_percent.'% offer ('.Money::format((float) $linkedOffer->offer_price).'). Awaiting PayPal payment.'
                : 'Order created. Awaiting PayPal payment.';

            $order = Order::query()->create([
                'buyer_id' => $user->id,
                'items' => $items,
                'total_amount' => $totalAmount,
                'platform_fee' => $fees['platformFee'],
                'seller_payout' => $fees['sellerPayout'],
                'currency' => 'USD',
                'note' => $note,
                'offer_id' => $linkedOffer?->id,
                'pickup_station_id' => null,
                'delivery_address' => $deliveryAddress,
                'pickup_address' => $resolvedPickup,
                'status' => 'pending_payment',
                'payment_status' => 'unpaid',
                'escrow_status' => 'none',
                'timeline' => [[
                    'status' => 'pending_payment',
                    'note' => $timelineNote,
                    'at' => now()->toISOString(),
                    'by' => $user->id,
                ]],
            ]);

            $this->orders->publish($order);
            $this->orders->reserveProducts($order);

            Offer::query()
                ->whereIn('product_id', $productIds)
                ->when($linkedOffer, fn ($q) => $q->where('id', '!=', $linkedOffer->id))
                ->whereIn('status', ['pending', 'accepted'])
                ->whereNull('order_id')
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'message' => 'Cancelled because the item was reserved by another checkout.',
                ]);

            if ($linkedOffer) {
                $linkedOffer->order_id = $order->id;
                $linkedOffer->save();
            }

            $sellerIds = collect($items)->pluck('seller')->filter()->map(fn ($id) => (int) $id)->unique();
            foreach ($sellerIds as $sellerId) {
                $this->notifySafe(
                    $sellerId,
                    'New order received',
                    ($user->full_name ?: 'A buyer').' ordered your item(s). Total: '.Money::format((float) $totalAmount).'. Waiting for payment.',
                    'offer',
                    '/orders/'.$order->id,
                    ['orderId' => (string) $order->id, 'type' => 'order']
                );
            }

            $this->notifySafe(
                (int) $user->id,
                'Order placed',
                'Pay '.Money::format((float) $totalAmount).' via PayPal to confirm your order.',
                'system',
                '/orders/'.$order->id,
                ['orderId' => (string) $order->id]
            );

            $this->adminNotifications->orderCreated($order);

            return response()->json([
                'message' => 'Order created successfully. Complete PayPal payment to continue.',
                'order' => Serializers::order($order->fresh(['buyer', 'shipper', 'pickupStation', 'offer'])),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create order.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function mine(Request $request)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            $orders = Order::query()
                ->with(['buyer', 'shipper', 'pickupStation', 'offer'])
                ->where('buyer_id', $user->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Order $order) => Serializers::order($order))
                ->values();

            return response()->json(['orders' => $orders]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch orders.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function selling(Request $request)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            $orders = Order::query()
                ->with(['buyer', 'shipper', 'pickupStation', 'offer'])
                ->whereRaw('JSON_CONTAINS(items, CAST(? AS JSON))', [json_encode(['seller' => (int) $user->id])])
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Order $order) => Serializers::order($order))
                ->values();

            return response()->json(['orders' => $orders]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch selling orders.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Request $request, string $id)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            $loaded = $this->loadOrderForUser($id, $user);
            if (isset($loaded['error'])) {
                return response()->json(['message' => $loaded['error']['message']], $loaded['error']['status']);
            }

            return response()->json(['order' => Serializers::order($loaded['order'])]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch order.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function pay(Request $request, string $id)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            $order = Order::query()->find($id);
            if (! $order) {
                return response()->json(['message' => 'Order not found.'], 404);
            }
            if (! $this->isBuyer($order, $user->id)) {
                return response()->json(['message' => 'Only the buyer can pay.'], 403);
            }

            $status = $order->normalizedStatus();
            if (! in_array($status, ['pending_payment', 'pending'], true)) {
                return response()->json([
                    'message' => 'Order cannot be paid in status "'.$status.'".',
                ], 400);
            }

            if (! $this->paypal->isConfigured()) {
                return response()->json([
                    'message' => 'PayPal sandbox is not configured. Add PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET to .env.',
                ], 503);
            }

            $this->orders->applyFees($order);

            $publicBase = rtrim((string) config('rebox.public_base_url'), '/');
            $paypalOrder = $this->paypal->createOrder(
                (float) $order->total_amount,
                $order->currency ?: 'USD',
                $publicBase.'/api/payments/paypal/return?orderId='.$order->id,
                $publicBase.'/api/payments/paypal/cancel?orderId='.$order->id,
                (string) $order->id
            );

            $approveUrl = $paypalOrder['approveUrl'] ?? null;
            if (! $approveUrl) {
                return response()->json(['message' => 'PayPal did not return an approve URL.'], 502);
            }

            $order->paypal_order_id = (string) ($paypalOrder['id'] ?? '');
            $order->payment_status = 'pending';
            $this->orders->pushTimeline($order, 'pending_payment', 'PayPal checkout started.', (int) $user->id);
            $order->save();
            $this->orders->publish($order);

            return response()->json([
                'message' => 'PayPal order created.',
                'approveUrl' => $approveUrl,
                'paypalOrderId' => $paypalOrder['id'] ?? null,
                'order' => Serializers::order($order->fresh(['buyer', 'shipper', 'pickupStation', 'offer'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Failed to start PayPal payment.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function capturePayment(Request $request, string $id)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            $order = Order::query()->find($id);
            if (! $order) {
                return response()->json(['message' => 'Order not found.'], 404);
            }
            if (! $this->isBuyer($order, $user->id) && $user->role !== 'admin') {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            if ($order->normalizedStatus() === 'paid' || $order->payment_status === 'paid') {
                return response()->json([
                    'message' => 'Already paid.',
                    'order' => Serializers::order($order->fresh(['buyer', 'shipper', 'pickupStation', 'offer'])),
                ]);
            }

            $paypalOrderId = $request->input('paypalOrderId') ?: $order->paypal_order_id;
            if (! $paypalOrderId) {
                return response()->json(['message' => 'Missing PayPal order id.'], 400);
            }

            $captureResult = $this->paypal->captureOrder((string) $paypalOrderId);
            $order->paypal_order_id = (string) $paypalOrderId;
            $order->save();

            $this->markPaidAndNotify($order->fresh(), (string) ($captureResult['captureId'] ?? ''), (int) $user->id);

            return response()->json([
                'message' => 'Payment captured. Escrow held.',
                'order' => Serializers::order($order->fresh(['buyer', 'shipper', 'pickupStation', 'offer'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Failed to capture payment.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function sellerConfirm(Request $request, string $id)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            $order = Order::query()->find($id);
            if (! $order) {
                return response()->json(['message' => 'Order not found.'], 404);
            }
            if (! $this->isSellerOnOrder($order, $user->id) && $user->role !== 'admin') {
                return response()->json(['message' => 'Only the seller can confirm.'], 403);
            }

            if ($order->normalizedStatus() !== 'paid') {
                return response()->json([
                    'message' => 'Order must be paid before seller confirmation.',
                ], 400);
            }

            if ($request->filled('pickupAddress')) {
                $order->pickup_address = Validators::normalizeAddress($request->input('pickupAddress') ?: []);
            } else {
                $currentPickup = Validators::normalizeAddress($order->pickup_address);
                if ($currentPickup['line1'] === '') {
                    $seller = User::query()->find($user->id);
                    $sellerPickup = Validators::normalizeAddress($seller?->pickup_address);
                    if ($sellerPickup['line1'] !== '') {
                        $order->pickup_address = Validators::normalizeAddress([
                            ...$sellerPickup,
                            'fullName' => $sellerPickup['fullName'] !== ''
                                ? $sellerPickup['fullName']
                                : (string) ($seller?->full_name ?? ''),
                            'phone' => $sellerPickup['phone'] !== ''
                                ? $sellerPickup['phone']
                                : (string) ($seller?->phone ?? ''),
                        ]);
                    }
                }
            }

            $order->status = 'seller_confirmed';
            $this->orders->pushTimeline($order, 'seller_confirmed', 'Seller confirmed stock.', (int) $user->id);
            $order->save();
            $this->orders->publish($order);

            $this->notifySafe(
                (int) $order->buyer_id,
                'Seller confirmed',
                'Your order was confirmed. A shipper will be assigned next.',
                'system',
                '/orders/'.$order->id,
                ['orderId' => (string) $order->id]
            );

            return response()->json([
                'message' => 'Order confirmed by seller.',
                'order' => Serializers::order($order->fresh(['buyer', 'shipper', 'pickupStation', 'offer'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to confirm order.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function sellerReject(Request $request, string $id)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            $order = Order::query()->find($id);
            if (! $order) {
                return response()->json(['message' => 'Order not found.'], 404);
            }
            if (! $this->isSellerOnOrder($order, $user->id) && $user->role !== 'admin') {
                return response()->json(['message' => 'Only the seller can reject.'], 403);
            }

            $status = $order->normalizedStatus();
            if (! in_array($status, ['paid', 'pending_payment', 'pending'], true)) {
                return response()->json([
                    'message' => 'Cannot reject order in status "'.$status.'".',
                ], 400);
            }

            $reason = trim((string) ($request->input('reason') ?: 'Seller rejected the order.'));
            $refund = $order->escrow_status === 'held' || $order->payment_status === 'paid';
            $timelineNote = $refund
                ? $reason.' Escrow marked refunded (demo — release manually in PayPal if needed).'
                : $reason;

            $this->orders->cancel($order, $timelineNote, (int) $user->id, $refund);
            if ($refund) {
                $order->cancel_reason = $reason;
                $order->save();
            }

            $this->notifySafe(
                (int) $order->buyer_id,
                'Order cancelled by seller',
                $reason,
                'system',
                '/orders/'.$order->id,
                ['orderId' => (string) $order->id]
            );

            return response()->json([
                'message' => 'Order rejected and cancelled.',
                'order' => Serializers::order($order->fresh(['buyer', 'shipper', 'pickupStation', 'offer'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to reject order.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function cancel(Request $request, string $id)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            $order = Order::query()->find($id);
            if (! $order) {
                return response()->json(['message' => 'Order not found.'], 404);
            }

            $buyer = $this->isBuyer($order, $user->id);
            $admin = $user->role === 'admin';
            if (! $buyer && ! $admin) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $status = $order->normalizedStatus();
            if (! in_array($status, ['pending_payment', 'pending', 'paid'], true)) {
                return response()->json([
                    'message' => 'Cannot cancel after logistics started (status: '.$status.').',
                ], 400);
            }

            $reason = trim((string) ($request->input('reason') ?: 'Cancelled by buyer.'));
            $refund = $order->escrow_status === 'held' || $order->payment_status === 'paid';
            $this->orders->cancel($order, $reason, (int) $user->id, $refund);

            $sellerIds = collect($order->items ?? [])->pluck('seller')->filter()->map(fn ($id) => (int) $id)->unique();
            foreach ($sellerIds as $sellerId) {
                $this->notifySafe(
                    $sellerId,
                    'Order cancelled',
                    $reason,
                    'offer',
                    '/orders/'.$order->id,
                    ['orderId' => (string) $order->id]
                );
            }

            return response()->json([
                'message' => 'Order cancelled.',
                'order' => Serializers::order($order->fresh(['buyer', 'shipper', 'pickupStation', 'offer'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to cancel order.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function assignShipper(Request $request, string $id)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            $order = Order::query()->find($id);
            if (! $order) {
                return response()->json(['message' => 'Order not found.'], 404);
            }

            $status = $order->normalizedStatus();
            if ($status !== 'seller_confirmed' && $status !== 'pickup_assigned') {
                return response()->json([
                    'message' => 'Order must be seller_confirmed before assigning a shipper.',
                ], 400);
            }

            $shipperId = $request->input('shipperId');
            if (! $shipperId && $user->role === 'shipper') {
                $shipperId = $user->id;
            }
            if (! $shipperId) {
                return response()->json(['message' => 'shipperId is required.'], 400);
            }
            if ($user->role !== 'admin' && (int) $shipperId !== (int) $user->id) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $shipper = User::query()->find($shipperId);
            if (! $shipper || $shipper->role !== 'shipper') {
                return response()->json(['message' => 'Invalid shipper user.'], 400);
            }

            $order->shipper_id = $shipper->id;
            $order->status = 'pickup_assigned';
            $order->assigned_at = now();

            $estimatedDeliveryAt = $this->parseDate($request->input('estimatedDeliveryAt'))
                ?: now()->addHours(4);
            $estimatedPickupAt = $this->parseDate($request->input('estimatedPickupAt'))
                ?: now()->addHours(2);

            $order->estimated_delivery_at = $estimatedDeliveryAt;
            $order->estimated_pickup_at = $estimatedPickupAt;
            $this->ensureOrderEtas($order);

            $this->orders->pushTimeline(
                $order,
                'pickup_assigned',
                'Shipper assigned: '.$shipper->full_name.'. ETA delivery '.$estimatedDeliveryAt->toDateTimeString().'.',
                (int) $user->id
            );
            $order->save();
            $this->orders->publish($order);

            $this->notifySafe(
                (int) $shipper->id,
                'New delivery job',
                'Pickup & deliver order '.$order->id,
                'system',
                '/shipper',
                ['orderId' => (string) $order->id]
            );

            $this->notifySafe(
                (int) $order->buyer_id,
                'Shipper assigned',
                $shipper->full_name.' will deliver by '.$estimatedDeliveryAt->toDateTimeString().'.',
                'system',
                '/orders/'.$order->id,
                ['orderId' => (string) $order->id]
            );

            return response()->json([
                'message' => 'Shipper assigned.',
                'order' => Serializers::order($order->fresh(['buyer', 'shipper', 'pickupStation', 'offer'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to assign shipper.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function shipperStatus(Request $request, string $id)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            $order = Order::query()->find($id);
            if (! $order) {
                return response()->json(['message' => 'Order not found.'], 404);
            }

            if (! $this->isShipperOnOrder($order, $user->id) && $user->role !== 'admin') {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $next = trim((string) $request->input('status', ''));
            $note = trim((string) $request->input('note', ''));
            $pickupCheck = strtolower(trim((string) $request->input('pickupCheck', '')));
            $current = $order->normalizedStatus();

            $transitions = [
                'pickup_assigned' => ['picked_up'],
                'picked_up' => ['out_for_delivery'],
                'out_for_delivery' => ['delivered'],
            ];

            if (! in_array($next, $transitions[$current] ?? [], true)) {
                return response()->json([
                    'message' => 'Cannot move from "'.$current.'" to "'.$next.'".',
                ], 400);
            }

            // Pickup gate: shipper must confirm item matches listing, or report mismatch.
            if ($current === 'pickup_assigned' && $next === 'picked_up') {
                if (! in_array($pickupCheck, ['match', 'mismatch'], true)) {
                    return response()->json([
                        'message' => 'Confirm pickup check: send pickupCheck=match or pickupCheck=mismatch.',
                    ], 400);
                }

                if ($pickupCheck === 'mismatch') {
                    if (mb_strlen($note) < 10) {
                        return response()->json([
                            'message' => 'Describe the mismatch in note (min 10 characters).',
                        ], 400);
                    }

                    $reason = 'Shipper pickup mismatch: '.$note;
                    $order->status = 'disputed';
                    $order->cancel_reason = $reason;
                    $this->orders->pushTimeline(
                        $order,
                        'disputed',
                        $reason,
                        (int) $user->id
                    );
                    $order->save();
                    $this->orders->publish($order);

                    $this->notifySafe(
                        (int) $order->buyer_id,
                        'Pickup issue — order disputed',
                        'The courier reported the item does not match the listing. ReBox will review. '.$note,
                        'system',
                        '/orders/'.$order->id,
                        ['orderId' => (string) $order->id, 'type' => 'dispute']
                    );

                    $sellerIds = collect($order->items ?? [])
                        ->pluck('seller')
                        ->filter()
                        ->map(fn ($id) => (int) $id)
                        ->unique();
                    foreach ($sellerIds as $sellerId) {
                        $this->notifySafe(
                            $sellerId,
                            'Pickup mismatch reported',
                            'Courier could not pick up your item — it may not match the listing. '.$note,
                            'system',
                            '/orders/'.$order->id,
                            ['orderId' => (string) $order->id, 'type' => 'dispute']
                        );
                    }

                    $this->adminNotifications->orderDisputed($order, $reason);

                    return response()->json([
                        'message' => 'Mismatch reported. Order moved to dispute for admin review. Item was not marked picked up.',
                        'order' => Serializers::order($order->fresh(['buyer', 'shipper', 'pickupStation', 'offer'])),
                    ]);
                }
            }

            if ($request->filled('estimatedDeliveryAt')) {
                $eta = $this->parseDate($request->input('estimatedDeliveryAt'));
                if ($eta) {
                    $order->estimated_delivery_at = $eta;
                }
            }
            if ($request->filled('estimatedPickupAt')) {
                $eta = $this->parseDate($request->input('estimatedPickupAt'));
                if ($eta) {
                    $order->estimated_pickup_at = $eta;
                }
            }

            $order->status = $next;
            if ($next === 'picked_up') {
                $order->picked_up_at = now();
            }
            if ($next === 'delivered') {
                $order->delivered_at = now();
                $hours = (int) config('rebox.buyer_confirm_hours', 48);
                $order->auto_complete_at = now()->addHours($hours);
            }
            $this->ensureOrderEtas($order);

            $etaNote = $order->estimated_delivery_at
                ? ' ETA '.$order->estimated_delivery_at->toDateTimeString().'.'
                : '';
            $defaultNote = $next === 'picked_up'
                ? 'Courier confirmed item matches listing and picked up.'.$etaNote
                : 'Status updated to '.$next.'.'.$etaNote;
            $this->orders->pushTimeline(
                $order,
                $next,
                $note !== '' ? $note : $defaultNote,
                (int) $user->id
            );
            $order->save();
            $this->orders->publish($order);

            $label = str_replace('_', ' ', $next);
            $body = $note !== ''
                ? $note
                : 'Your order is now '.$label.'.'.(
                    $order->estimated_delivery_at && $next !== 'delivered'
                        ? ' Expected by '.$order->estimated_delivery_at->toDateTimeString().'.'
                        : ''
                );
            if ($next === 'delivered') {
                $hours = (int) config('rebox.buyer_confirm_hours', 48);
                $body .= ' Please inspect the item within '.$hours.' hours, or escrow may auto-release.';
            }

            $this->notifySafe(
                (int) $order->buyer_id,
                'Order '.$label,
                $body,
                'system',
                '/orders/'.$order->id,
                ['orderId' => (string) $order->id]
            );

            return response()->json([
                'message' => 'Shipment status updated.',
                'order' => Serializers::order($order->fresh(['buyer', 'shipper', 'pickupStation', 'offer'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to update shipment status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function shipperJobs(Request $request)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            if (! in_array($user->role, ['shipper', 'admin'], true)) {
                return response()->json(['message' => 'Shipper role required.'], 403);
            }

            $available = Order::query()
                ->with(['buyer', 'shipper', 'pickupStation', 'offer'])
                ->where('status', 'seller_confirmed')
                ->whereNull('shipper_id')
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (Order $order) => Serializers::order($order))
                ->values();

            $mine = Order::query()
                ->with(['buyer', 'shipper', 'pickupStation', 'offer'])
                ->where('shipper_id', $user->id)
                ->whereIn('status', [
                    'pickup_assigned',
                    'picked_up',
                    'out_for_delivery',
                    'delivered',
                ])
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (Order $order) => Serializers::order($order))
                ->values();

            return response()->json([
                'available' => $available,
                'mine' => $mine,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to list shipper jobs.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function confirmDelivery(Request $request, string $id)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            $order = Order::query()->find($id);
            if (! $order) {
                return response()->json(['message' => 'Order not found.'], 404);
            }
            if (! $this->isBuyer($order, $user->id) && $user->role !== 'admin') {
                return response()->json(['message' => 'Only the buyer can confirm.'], 403);
            }

            if ($order->normalizedStatus() !== 'delivered') {
                return response()->json([
                    'message' => 'Order must be delivered before confirmation.',
                ], 400);
            }

            $this->orders->applyFees($order);
            $this->orders->complete(
                $order,
                (int) $user->id,
                'Buyer confirmed receipt. Escrow released to seller ('.Money::format((float) $order->seller_payout).').'
            );

            $sellerIds = collect($order->items ?? [])->pluck('seller')->filter()->map(fn ($id) => (int) $id)->unique();
            foreach ($sellerIds as $sellerId) {
                $this->notifySafe(
                    $sellerId,
                    'Escrow released',
                    'Buyer confirmed. Payout '.Money::format((float) $order->seller_payout).' (demo ledger).',
                    'offer',
                    '/orders/'.$order->id,
                    ['orderId' => (string) $order->id]
                );
            }

            return response()->json([
                'message' => 'Order completed. Escrow released.',
                'order' => Serializers::order($order->fresh(['buyer', 'shipper', 'pickupStation', 'offer'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to confirm order.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function dispute(Request $request, string $id)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            $order = Order::query()->find($id);
            if (! $order) {
                return response()->json(['message' => 'Order not found.'], 404);
            }
            if (! $this->isBuyer($order, $user->id) && $user->role !== 'admin') {
                return response()->json(['message' => 'Only the buyer can dispute.'], 403);
            }

            $status = $order->normalizedStatus();
            if (! in_array($status, ['delivered', 'out_for_delivery'], true)) {
                return response()->json([
                    'message' => 'Disputes can be opened after delivery starts.',
                ], 400);
            }

            $reason = trim((string) $request->input('reason', ''));
            if (mb_strlen($reason) < 10) {
                return response()->json([
                    'message' => 'Dispute reason must be at least 10 characters.',
                ], 400);
            }

            $evidence = collect($request->input('evidence', []))
                ->map(fn ($item) => (string) $item)
                ->take(8)
                ->values()
                ->all();

            $order->status = 'disputed';
            $order->dispute = [
                'reason' => $reason,
                'evidence' => $evidence,
                'createdAt' => now()->toISOString(),
                'createdBy' => $user->id,
                'resolvedAt' => null,
                'resolution' => '',
                'resolutionStatus' => '',
            ];
            $this->orders->pushTimeline($order, 'disputed', $reason, (int) $user->id);
            $order->save();
            $this->orders->publish($order);

            $firstSeller = $order->items[0]['seller'] ?? null;
            if ($firstSeller) {
                $this->notifySafe(
                    (int) $firstSeller,
                    'Order disputed',
                    $reason,
                    'offer',
                    '/orders/'.$order->id,
                    ['orderId' => (string) $order->id]
                );
            }

            $this->adminNotifications->orderDisputed($order, $reason);

            return response()->json([
                'message' => 'Dispute opened. Admin will review.',
                'order' => Serializers::order($order->fresh(['buyer', 'shipper', 'pickupStation', 'offer'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to open dispute.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function resolveDispute(Request $request, string $id)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            if ($user->role !== 'admin') {
                return response()->json(['message' => 'Admin only.'], 403);
            }

            $order = Order::query()->find($id);
            if (! $order) {
                return response()->json(['message' => 'Order not found.'], 404);
            }
            if ($order->normalizedStatus() !== 'disputed') {
                return response()->json(['message' => 'Order is not disputed.'], 400);
            }

            $resolutionStatus = trim((string) $request->input('resolutionStatus', ''));
            $resolution = trim((string) $request->input('resolution', ''));

            if (! in_array($resolutionStatus, ['completed', 'cancelled', 'refunded'], true)) {
                return response()->json([
                    'message' => 'resolutionStatus must be completed, cancelled, or refunded.',
                ], 400);
            }

            $dispute = $order->dispute ?? [];
            $dispute['resolvedAt'] = now()->toISOString();
            $dispute['resolution'] = $resolution;
            $dispute['resolutionStatus'] = $resolutionStatus;
            $order->dispute = $dispute;

            $resolveNote = 'Dispute resolved: '.$resolutionStatus.'. '.$resolution;

            if ($resolutionStatus === 'completed') {
                $this->orders->complete($order, (int) $user->id, $resolveNote);
            } else {
                $cancelReason = $resolution !== '' ? $resolution : 'Dispute resolved with refund.';
                $this->orders->cancel($order, $cancelReason, (int) $user->id, true);
                $timeline = $order->timeline ?? [];
                if ($timeline !== []) {
                    $timeline[count($timeline) - 1]['note'] = $resolveNote;
                    $order->timeline = $timeline;
                    $order->cancel_reason = $cancelReason;
                    $order->save();
                    $this->orders->publish($order);
                }
            }

            return response()->json([
                'message' => 'Dispute resolved.',
                'order' => Serializers::order($order->fresh(['buyer', 'shipper', 'pickupStation', 'offer'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to resolve dispute.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function autoComplete(Request $request)
    {
        try {
            /** @var User $user */
            $user = $request->attributes->get('authUser');
            if ($user->role !== 'admin') {
                return response()->json(['message' => 'Admin only.'], 403);
            }

            $due = Order::query()
                ->where('status', 'delivered')
                ->whereNotNull('auto_complete_at')
                ->where('auto_complete_at', '<=', now())
                ->get();

            $completed = 0;
            foreach ($due as $order) {
                $this->orders->complete(
                    $order,
                    null,
                    'Auto-completed after buyer confirm window.'
                );
                $completed++;
            }

            return response()->json([
                'message' => 'Auto-complete finished.',
                'completed' => $completed,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Auto-complete failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @return array{order: Order}|array{error: array{status: int, message: string}}
     */
    protected function loadOrderForUser(string $id, User $user): array
    {
        $order = Order::query()
            ->with(['buyer', 'shipper', 'pickupStation', 'offer'])
            ->find($id);
        if (! $order) {
            return ['error' => ['status' => 404, 'message' => 'Order not found.']];
        }
        if (! $this->orders->canView($order, $user)) {
            return ['error' => ['status' => 403, 'message' => 'Forbidden.']];
        }

        return ['order' => $order];
    }

    protected function markPaidAndNotify(Order $order, string $captureId = '', ?int $actorId = null): Order
    {
        $order = $this->orders->markPaid($order, $captureId);

        // Enrich timeline note to match Express fee wording when possible.
        $timeline = $order->timeline ?? [];
        if ($timeline !== []) {
            $timeline[count($timeline) - 1]['note'] = 'Payment captured via PayPal. Escrow held. Fee '.Money::format((float) $order->platform_fee).'.';
            if ($actorId !== null) {
                $timeline[count($timeline) - 1]['by'] = $actorId;
            }
            $order->timeline = $timeline;
            $order->save();
        }

        $sellerIds = collect($order->items ?? [])->pluck('seller')->filter()->map(fn ($id) => (int) $id)->unique();
        foreach ($sellerIds as $sellerId) {
            $this->notifySafe(
                $sellerId,
                'Order paid — confirm stock',
                'Buyer paid '.Money::format((float) $order->total_amount).'. Please confirm you still have the item(s).',
                'offer',
                '/orders/'.$order->id,
                ['orderId' => (string) $order->id, 'type' => 'order']
            );
        }

        $this->notifySafe(
            (int) $order->buyer_id,
            'Payment successful',
            'Funds are held in escrow until delivery is confirmed.',
            'system',
            '/orders/'.$order->id,
            ['orderId' => (string) $order->id]
        );

        return $order;
    }

    protected function isBuyer(Order $order, int|string $userId): bool
    {
        return (int) $order->buyer_id === (int) $userId;
    }

    protected function isSellerOnOrder(Order $order, int|string $userId): bool
    {
        foreach ($order->items ?? [] as $item) {
            $seller = $item['seller'] ?? $item['seller_id'] ?? null;
            $sellerId = is_array($seller) ? ($seller['id'] ?? null) : $seller;
            if ((int) $sellerId === (int) $userId) {
                return true;
            }
        }

        return false;
    }

    protected function isShipperOnOrder(Order $order, int|string $userId): bool
    {
        return $order->shipper_id && (int) $order->shipper_id === (int) $userId;
    }

    protected function ensureOrderEtas(Order $order): void
    {
        $assignedAt = $order->assigned_at ?? $order->paid_at ?? $order->created_at;

        if (! $order->estimated_pickup_at) {
            if ($order->picked_up_at) {
                $order->estimated_pickup_at = $order->picked_up_at;
            } elseif ($order->shipper_id || $assignedAt) {
                $order->estimated_pickup_at = Carbon::parse($assignedAt)->addHours(2);
            }
        }

        if (! $order->estimated_delivery_at) {
            if ($order->delivered_at) {
                $order->estimated_delivery_at = $order->delivered_at;
            } elseif ($order->picked_up_at) {
                $order->estimated_delivery_at = Carbon::parse($order->picked_up_at)->addHours(2);
            } elseif ($order->shipper_id || $assignedAt) {
                $order->estimated_delivery_at = Carbon::parse($assignedAt)->addHours(4);
            }
        }
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        return $date;
    }

    protected function writeSse(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data)."\n\n";
        $this->flushSse();
    }

    protected function flushSse(): void
    {
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();
    }

    protected function notifySafe(
        int $userId,
        string $title,
        string $body,
        string $type = 'system',
        ?string $link = null,
        array $data = []
    ): void {
        try {
            $this->notifications->createAndPush($userId, $title, $body, $type, $link, $data);
        } catch (\Throwable) {
            // Match Express fire-and-forget notification behavior.
        }
    }
}
