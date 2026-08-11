<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiTrainingSample extends Model
{
    protected $fillable = [
        'product_id',
        'user_id',
        'image_urls',
        'category_slug',
        'ai_draft',
        'user_final',
        'admin_label',
        'rejection_reason',
        'model_version',
    ];

    protected function casts(): array
    {
        return [
            'image_urls' => 'array',
            'ai_draft' => 'array',
            'user_final' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
