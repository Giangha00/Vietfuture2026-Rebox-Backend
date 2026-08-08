<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\AdminNotificationService;
use App\Services\OrderService;
use App\Services\PaypalService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected PaypalService $paypal,
        protected OrderService $orders,
        protected NotificationService $notifications,
        protected AdminNotificationService $adminNotifications,
    ) {}

    public function status()
    {
        $clientId = (string) config('rebox.paypal.client_id');

        return response()->json([
            'configured' => $this->paypal->isConfigured(),
            'mode' => $this->paypal->mode(),
            'platformFeePercent' => (float) config('rebox.platform_fee_percent', 10),
            'clientId' => $clientId ? substr($clientId, 0, 8).'…' : '',
        ]);
    }

    public function paypalReturn(Request $request)
    {
        $frontend = rtrim((string) config('rebox.frontend_url'), '/');
        $orderId = $request->query('orderId');
        $token = $request->query('token');

        if (! $orderId) {
            return redirect($frontend.'/orders?payment=missing');
        }

        $order = Order::query()->find($orderId);
        if (! $order) {
            return redirect($frontend.'/orders?payment=not_found');
        }

        try {
            $paypalOrderId = $token ?: $order->paypal_order_id;
            if ($order->payment_status !== 'paid') {
                $capture = $this->paypal->captureOrder($paypalOrderId);
                $this->orders->markPaid($order->fresh(), $capture['captureId'] ?? '');
                $order = $order->fresh();
                foreach (collect($order->items)->pluck('seller')->unique() as $sellerId) {
                    if ($sellerId) {
                        $this->notifications->createAndPush(
                            (int) $sellerId,
                            'Order paid',
                            'A buyer paid for your item. Please confirm stock.',
                            'system',
                            '/orders/selling',
                            ['orderId' => (string) $order->id]
                        );
                    }
                }
                $this->notifications->createAndPush(
                    (int) $order->buyer_id,
                    'Payment successful',
                    'Your payment was captured. Waiting for seller confirmation.',
                    'system',
                    '/orders/'.$order->id,
                    ['orderId' => (string) $order->id]
                );
                $this->adminNotifications->orderPaid($order);
            }

            return redirect($frontend.'/orders/'.$order->id.'?payment=success');
        } catch (\Throwable $e) {
            return redirect($frontend.'/orders/'.$order->id.'?payment=failed');
        }
    }

    public function paypalCancel(Request $request)
    {
        $frontend = rtrim((string) config('rebox.frontend_url'), '/');
        $orderId = $request->query('orderId');

        return redirect($frontend.'/orders/'.($orderId ?: '').'?payment=cancelled');
    }
}
