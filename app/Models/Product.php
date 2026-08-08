<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'price',
        'title',
        'brand',
        'description',
        'condition',
        'attributes',
        'images',
        'is_verified',
        'moderation_status',
        'rejection_reason',
        'moderation_notes',
        'moderated_at',
        'moderated_by',
        'seller_id',
        'category_id',
        'station_id',
        'status',
        'accepts_offers',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'images' => 'array',
            'attributes' => 'array',
            'is_verified' => 'boolean',
            'accepts_offers' => 'boolean',
            'moderated_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }
}
