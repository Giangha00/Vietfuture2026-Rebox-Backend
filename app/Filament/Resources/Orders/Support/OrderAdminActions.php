<?php

namespace App\Filament\Resources\Orders\Support;

use App\Models\Order;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\OrderService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class OrderAdminActions
{
    /** @see OrderService::STATUS_OPTIONS */
    public const STATUS_OPTIONS = OrderService::STATUS_OPTIONS;

    /** @see OrderService::FLOW */
    public const FLOW = OrderService::FLOW;

    public static function updateStatus(Order $order, string $status, string $note = ''): void
    {
        $service = app(OrderService::class);
        $by = Auth::id();
        $note = trim($note) !== '' ? trim($note) : 'Status updated by admin.';

        if ($status === 'completed') {
            $service->complete($order, $by, $note);
            self::notifyParties($order->fresh(), 'Order completed', $note);
            Notification::make()->title('Order marked completed')->success()->send();

            return;
        }

        if ($status === 'cancelled') {
            $service->cancel($order, $note, $by, true);
            self::notifyParties($order->fresh(), 'Order cancelled', $note);
            Notification::make()->title('Order cancelled')->success()->send();

            return;
        }

        $order->status = $status;

        match ($status) {
            'paid' => tap($order, function (Order $order) {
                $order->payment_status = 'paid';
                $order->escrow_status = 'held';
                $order->paid_at = $order->paid_at ?? now();
            }),
            'picked_up' => tap($order, function (Order $order) {
                $order->picked_up_at = $order->picked_up_at ?? now();
            }),
            'delivered' => tap($order, function (Order $order) {
                $order->delivered_at = $order->delivered_at ?? now();
                $order->auto_complete_at = $order->auto_complete_at ?? now()->addHours((int) config('rebox.buyer_confirm_hours', 48));
            }),
            'disputed' => tap($order, function (Order $order) use ($note) {
                $dispute = $order->dispute ?? [];
                if (empty($dispute['reason'])) {
                    $dispute['reason'] = $note;
                    $dispute['createdAt'] = now()->toISOString();
                    $dispute['createdBy'] = Auth::id();
                    $dispute['resolvedAt'] = null;
                    $dispute['resolution'] = '';
                    $dispute['resolutionStatus'] = '';
                    $order->dispute = $dispute;
                }
            }),
            default => null,
        };

        $service->pushTimeline($order, $status, $note, $by);
        $order->save();
        $service->publish($order);

        self::notifyParties($order->fresh(), 'Order status updated', "Order #{$order->id} is now ".$status.'. '.$note);
        Notification::make()->title('Order status updated')->success()->send();
    }

    public static function assignShipper(Order $order, int $shipperId, string $note = ''): void
    {
        $service = app(OrderService::class);
        $order->shipper_id = $shipperId;
        $order->status = 'pickup_assigned';
        $order->assigned_at = now();
        $order->estimated_pickup_at = now()->addHours(2);
        $order->estimated_delivery_at = now()->addHours(4);
        $service->pushTimeline(
            $order,
            'pickup_assigned',
            $note !== '' ? $note : 'Shipper assigned by admin.',
            Auth::id()
        );
        $order->save();
        $service->publish($order);

        app(NotificationService::class)->createAndPush(
            $shipperId,
            'New delivery job',
            'You were assigned to order #'.$order->id,
            'system',
            '/shipper',
            ['orderId' => (string) $order->id]
        );

        self::notifyParties($order->fresh(), 'Shipper assigned', 'A shipper was assigned to order #'.$order->id.'.');
        Notification::make()->title('Shipper assigned')->success()->send();
    }

    public static function resolveDispute(Order $order, string $resolutionStatus, string $resolution = ''): void
    {
        $service = app(OrderService::class);
        $dispute = $order->dispute ?? [];
        $dispute['resolvedAt'] = now()->toISOString();
        $dispute['resolution'] = $resolution;
        $dispute['resolutionStatus'] = $resolutionStatus;
        $order->dispute = $dispute;

        if ($resolutionStatus === 'completed') {
            $service->complete($order, Auth::id(), $resolution ?: 'Dispute resolved: completed.');
        } else {
            $service->cancel($order, $resolution ?: 'Dispute resolved: refunded/cancelled.', Auth::id(), true);
        }

        self::notifyParties($order->fresh(), 'Dispute resolved', $resolution ?: 'Admin resolved the dispute.');
        Notification::make()->title('Dispute resolved')->success()->send();
    }

    public static function notifyParties(Order $order, string $title, string $body): void
    {
        $notifications = app(NotificationService::class);
        $ids = collect([$order->buyer_id, $order->shipper_id]);

        foreach ($order->items ?? [] as $item) {
            $seller = $item['seller'] ?? $item['seller_id'] ?? null;
            $sellerId = is_array($seller) ? ($seller['id'] ?? null) : $seller;
            if ($sellerId) {
                $ids->push($sellerId);
            }
        }

        foreach ($ids->filter()->unique() as $userId) {
            $notifications->createAndPush(
                (int) $userId,
                $title,
                $body,
                'system',
                '/orders/'.$order->id,
                ['orderId' => (string) $order->id, 'status' => $order->status]
            );
        }
    }

    public static function shipperOptions(): array
    {
        return User::query()
            ->where('role', 'shipper')
            ->orderBy('full_name')
            ->pluck('full_name', 'id')
            ->all();
    }

    public static function flowIndex(string $status): int
    {
        return OrderService::flowIndex($status);
    }
}
