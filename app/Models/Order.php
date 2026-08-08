<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    public const STATUSES = [
        'pending_payment',
        'paid',
        'seller_confirmed',
        'pickup_assigned',
        'picked_up',
        'out_for_delivery',
        'delivered',
        'completed',
        'cancelled',
        'disputed',
        'pending',
        'confirmed',
    ];

    protected $fillable = [
        'buyer_id',
        'items',
        'total_amount',
        'platform_fee',
        'seller_payout',
        'currency',
        'note',
        'offer_id',
        'pickup_station_id',
        'delivery_address',
        'pickup_address',
        'status',
        'payment_status',
        'escrow_status',
        'paypal_order_id',
        'paypal_capture_id',
        'paid_at',
        'shipper_id',
        'assigned_at',
        'estimated_delivery_at',
        'estimated_pickup_at',
        'picked_up_at',
        'delivered_at',
        'buyer_confirmed_at',
        'auto_complete_at',
        'completed_at',
        'cancelled_at',
        'cancel_reason',
        'timeline',
        'dispute',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'delivery_address' => 'array',
            'pickup_address' => 'array',
            'timeline' => 'array',
            'dispute' => 'array',
            'total_amount' => 'float',
            'platform_fee' => 'float',
            'seller_payout' => 'float',
            'paid_at' => 'datetime',
            'assigned_at' => 'datetime',
            'estimated_delivery_at' => 'datetime',
            'estimated_pickup_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'buyer_confirmed_at' => 'datetime',
            'auto_complete_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function pickupStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'pickup_station_id');
    }

    public function shipper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipper_id');
    }

    public function normalizedStatus(): string
    {
        return match ($this->status) {
            'pending' => 'pending_payment',
            'confirmed' => 'paid',
            default => $this->status,
        };
    }
}
