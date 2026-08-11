<?php

namespace App\Filament\Resources\Products\Support;

use App\Models\AiTrainingSample;
use App\Models\Product;
use App\Services\NotificationService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class ProductModeration
{
    /**
     * Approve & list. Does NOT grant the Verified badge.
     * Approved = listing cleared review; Verified = separate QC badge.
     */
    public static function approve(Product $product, string $notes = ''): void
    {
        $notes = trim($notes);

        $product->update([
            'moderation_status' => 'approved',
            'is_verified' => false,
            'status' => 'active',
            'rejection_reason' => '',
            'moderation_notes' => $notes !== '' ? $notes : ($product->moderation_notes ?? ''),
            'moderated_at' => now(),
            'moderated_by' => Auth::id(),
        ]);

        self::labelTrainingSample($product, 'approved');

        app(NotificationService::class)->createAndPush(
            (int) $product->seller_id,
            'Product approved & listed',
            "Your product \"{$product->title}\" has been approved and is now listed on ReBox.",
            'listing',
            '/products/'.$product->id,
            [
                'productId' => (string) $product->id,
                'moderationStatus' => 'approved',
            ]
        );

        Notification::make()
            ->title('Product approved')
            ->body('Listing is live. Use “Mark verified” only after extra QC if needed.')
            ->success()
            ->send();
    }

    public static function reject(Product $product, string $reason): void
    {
        $reason = trim($reason);

        $product->update([
            'moderation_status' => 'rejected',
            'is_verified' => false,
            'status' => 'archived',
            'rejection_reason' => $reason,
            'moderated_at' => now(),
            'moderated_by' => Auth::id(),
        ]);

        self::labelTrainingSample($product, 'rejected', $reason);

        app(NotificationService::class)->createAndPush(
            (int) $product->seller_id,
            'Product rejected',
            "Your product \"{$product->title}\" was rejected and will not be listed. Reason: {$reason}. Please create a new listing if you still want to sell.",
            'listing',
            '/post-item',
            [
                'productId' => (string) $product->id,
                'moderationStatus' => 'rejected',
                'rejectionReason' => $reason,
            ]
        );

        Notification::make()
            ->title('Product rejected')
            ->body('The seller has been notified with your rejection reason.')
            ->success()
            ->send();
    }

    /**
     * Extra QC badge — only for already approved listings.
     */
    public static function markVerified(Product $product, string $notes = ''): void
    {
        if ($product->moderation_status !== 'approved') {
            Notification::make()
                ->title('Cannot verify')
                ->body('Only approved listings can receive the Verified badge.')
                ->danger()
                ->send();

            return;
        }

        $notes = trim($notes);
        $merged = $notes !== ''
            ? trim(($product->moderation_notes ? $product->moderation_notes."\n" : '').'[Verified] '.$notes)
            : $product->moderation_notes;

        $product->update([
            'is_verified' => true,
            'moderation_notes' => $merged,
            'moderated_at' => now(),
            'moderated_by' => Auth::id(),
        ]);

        app(NotificationService::class)->createAndPush(
            (int) $product->seller_id,
            'Listing verified',
            "Your product \"{$product->title}\" received the ReBox Verified badge.",
            'listing',
            '/products/'.$product->id,
            [
                'productId' => (string) $product->id,
                'isVerified' => true,
            ]
        );

        Notification::make()
            ->title('Marked verified')
            ->body('Verified badge is now visible on the listing.')
            ->success()
            ->send();
    }

    public static function unverify(Product $product, string $notes = ''): void
    {
        $notes = trim($notes);
        $merged = $notes !== ''
            ? trim(($product->moderation_notes ? $product->moderation_notes."\n" : '').'[Unverified] '.$notes)
            : $product->moderation_notes;

        $product->update([
            'is_verified' => false,
            'moderation_notes' => $merged,
            'moderated_at' => now(),
            'moderated_by' => Auth::id(),
        ]);

        Notification::make()
            ->title('Verified badge removed')
            ->success()
            ->send();
    }

    protected static function labelTrainingSample(
        Product $product,
        string $label,
        ?string $rejectionReason = null
    ): void {
        AiTrainingSample::query()
            ->where('product_id', $product->id)
            ->orderByDesc('id')
            ->limit(1)
            ->update([
                'admin_label' => $label,
                'rejection_reason' => $rejectionReason,
            ]);
    }
}
