<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offer extends Model
{
    public const PERCENTS = [5, 10, 15];

    protected $fillable = [
        'product_id',
        'buyer_id',
        'seller_id',
        'list_price',
        'discount_percent',
        'offer_price',
        'message',
        'status',
        'expires_at',
        'accepted_at',
        'rejected_at',
        'cancelled_at',
        'order_id',
    ];

    protected function casts(): array
    {
        return [
            'list_price' => 'float',
            'offer_price' => 'float',
            'discount_percent' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public static function calcOfferPrice(float $listPrice, int $discountPercent): float
    {
        $price = round($listPrice * (1 - $discountPercent / 100), 2);

        return max(0.01, $price);
    }

    public function expireIfNeeded(): void
    {
        if ($this->status === 'pending' && $this->expires_at && $this->expires_at->isPast()) {
            $this->status = 'expired';
            $this->save();
        }
    }
}
