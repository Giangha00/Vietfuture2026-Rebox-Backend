<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Event;

class OrderService
{
    public function pushTimeline(Order $order, string $status, string $note = '', ?int $by = null): void
    {
        $timeline = $order->timeline ?? [];
        $timeline[] = [
            'status' => $status,
            'note' => $note,
            'at' => now()->toISOString(),
            'by' => $by,
        ];
        $order->timeline = $timeline;
    }

    public function publish(Order $order, array $meta = []): void
    {
        $participants = collect([
            $order->buyer_id,
            $order->shipper_id,
        ]);
        foreach ($order->items ?? [] as $item) {
            $seller = $item['seller'] ?? $item['seller_id'] ?? null;
            if ($seller) {
                $participants->push(is_array($seller) ? ($seller['id'] ?? null) : $seller);
            }
        }

        Event::dispatch('order.updated', [[
            'type' => 'order.updated',
            'orderId' => (string) $order->id,
            'status' => $order->normalizedStatus(),
            'paymentStatus' => $order->payment_status,
            'escrowStatus' => $order->escrow_status,
            'participants' => $participants->filter()->map(fn ($id) => (string) $id)->unique()->values()->all(),
            'at' => now()->toISOString(),
            ...$meta,
        ]]);
    }

    public function canView(Order $order, User $user): bool
    {
        if (in_array($user->role, ['admin', 'shipper'], true) && $user->role === 'admin') {
            return true;
        }
        if ($user->role === 'shipper' && (int) $order->shipper_id === (int) $user->id) {
            return true;
        }
        if ((int) $order->buyer_id === (int) $user->id) {
            return true;
        }
        foreach ($order->items ?? [] as $item) {
            $seller = $item['seller'] ?? $item['seller_id'] ?? null;
            $sellerId = is_array($seller) ? ($seller['id'] ?? null) : $seller;
            if ((int) $sellerId === (int) $user->id) {
                return true;
            }
        }

        return $user->role === 'admin';
    }

    public function releaseProducts(Order $order, string $nextStatus = 'active'): void
    {
        foreach ($order->items ?? [] as $item) {
            $productId = $item['product'] ?? $item['product_id'] ?? null;
            if (! $productId) {
                continue;
            }
            $product = Product::query()->find($productId);
            if ($product && in_array($product->status, ['reserved', 'sold'], true)) {
                $product->status = $nextStatus;
                $product->save();
            }
        }
    }

    public function markProductsSold(Order $order): void
    {
        foreach ($order->items ?? [] as $item) {
            $productId = $item['product'] ?? $item['product_id'] ?? null;
            if (! $productId) {
                continue;
            }
            $product = Product::query()->find($productId);
            if ($product) {
                $product->status = 'sold';
                $product->save();
            }
        }
    }

    public function reserveProducts(Order $order): void
    {
        foreach ($order->items ?? [] as $item) {
            $productId = $item['product'] ?? $item['product_id'] ?? null;
            if (! $productId) {
                continue;
            }
            $product = Product::query()->find($productId);
            if ($product) {
                $product->status = 'reserved';
                $product->save();
            }
        }
    }

    public function applyFees(Order $order): void
    {
        $fees = Money::calcFees((float) $order->total_amount);
        $order->platform_fee = $fees['platformFee'];
        $order->seller_payout = $fees['sellerPayout'];
    }

    public function markPaid(Order $order, string $captureId = ''): Order
    {
        $order->status = 'paid';
        $order->payment_status = 'paid';
        $order->escrow_status = 'held';
        $order->paid_at = now();
        if ($captureId) {
            $order->paypal_capture_id = $captureId;
        }
        $this->applyFees($order);
        $this->pushTimeline($order, 'paid', 'Payment captured and held in escrow.');
        $order->save();
        $this->publish($order);

        return $order;
    }

    public function complete(Order $order, ?int $by = null, string $note = 'Order completed.'): Order
    {
        $order->status = 'completed';
        $order->buyer_confirmed_at = $order->buyer_confirmed_at ?? now();
        $order->completed_at = now();
        $order->escrow_status = 'released';
        $order->payment_status = 'released';
        $this->pushTimeline($order, 'completed', $note, $by);
        $order->save();
        $this->markProductsSold($order);
        $this->publish($order);

        return $order;
    }

    public function cancel(Order $order, string $reason = '', ?int $by = null, bool $refund = false): Order
    {
        $order->status = 'cancelled';
        $order->cancelled_at = now();
        $order->cancel_reason = $reason;
        if ($refund || in_array($order->payment_status, ['paid', 'pending'], true) || $order->escrow_status === 'held') {
            $order->escrow_status = 'refunded';
            $order->payment_status = 'refunded';
        }
        $this->pushTimeline($order, 'cancelled', $reason ?: 'Order cancelled.', $by);
        $order->save();
        $this->releaseProducts($order, 'active');
        $this->publish($order);

        return $order;
    }
}
