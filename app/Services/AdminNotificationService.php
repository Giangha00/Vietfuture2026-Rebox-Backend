<?php

namespace App\Services;

use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Collection;

class AdminNotificationService
{
    /**
     * @return Collection<int, User>
     */
    public function admins(): Collection
    {
        return User::query()
            ->where(function ($query) {
                $query->where('role', 'admin')
                    ->orWhereHas('roles', function ($roles) {
                        $roles->whereIn('name', ['super_admin', 'admin']);
                    });
            })
            ->get()
            ->unique('id')
            ->values();
    }

    public function notifyAdmins(
        string $title,
        string $body,
        ?string $url = null,
        string $status = 'info',
        string $actionLabel = 'View',
    ): void {
        try {
            $admins = $this->admins();
            if ($admins->isEmpty()) {
                return;
            }

            $builder = FilamentNotification::make()
                ->title($title)
                ->body($body)
                ->{$status}();

            if ($url) {
                $builder->actions([
                    Action::make('view')
                        ->label($actionLabel)
                        ->button()
                        ->url($url)
                        ->markAsRead(),
                ]);
            }

            // Sync write so alerts appear even without a queue worker.
            foreach ($admins as $admin) {
                $admin->notifyNow($builder->toDatabase());
                DatabaseNotificationsSent::dispatch($admin);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function productPendingReview(Product $product): void
    {
        $this->notifyAdmins(
            title: 'Listing pending review',
            body: "\"{$product->title}\" was submitted and needs moderation.",
            url: ProductResource::getUrl('view', ['record' => $product], panel: 'admin'),
            status: 'warning',
            actionLabel: 'Review listing',
        );
    }

    public function orderCreated(Order $order): void
    {
        $itemCount = is_array($order->items) ? count($order->items) : 0;
        $this->notifyAdmins(
            title: 'New order placed',
            body: "Order #{$order->id} · {$itemCount} item(s) · $".number_format((float) $order->total_amount, 2),
            url: OrderResource::getUrl('view', ['record' => $order], panel: 'admin'),
            status: 'success',
            actionLabel: 'Track order',
        );
    }

    public function orderPaid(Order $order): void
    {
        $this->notifyAdmins(
            title: 'Order paid',
            body: "Order #{$order->id} was paid via PayPal. Escrow is holding funds.",
            url: OrderResource::getUrl('view', ['record' => $order], panel: 'admin'),
            status: 'success',
            actionLabel: 'Track order',
        );
    }

    public function orderDisputed(Order $order, string $reason = ''): void
    {
        $snippet = trim($reason) !== ''
            ? mb_substr(trim($reason), 0, 120)
            : 'A buyer opened a dispute.';

        $this->notifyAdmins(
            title: 'Order disputed',
            body: "Order #{$order->id}: {$snippet}",
            url: OrderResource::getUrl('view', ['record' => $order], panel: 'admin'),
            status: 'danger',
            actionLabel: 'Resolve dispute',
        );
    }

    public function offerCreated(Offer $offer, string $productTitle = ''): void
    {
        $title = $productTitle !== '' ? $productTitle : 'a listing';
        $this->notifyAdmins(
            title: 'New offer submitted',
            body: "Offer #{$offer->id} (−{$offer->discount_percent}%) on \"{$title}\".",
            url: OfferResource::getUrl('edit', ['record' => $offer], panel: 'admin'),
            status: 'info',
            actionLabel: 'View offer',
        );
    }

    public function userRegistered(User $user): void
    {
        $this->notifyAdmins(
            title: 'New user registered',
            body: "{$user->full_name} ({$user->email}) just signed up.",
            url: UserResource::getUrl('edit', ['record' => $user], panel: 'admin'),
            status: 'info',
            actionLabel: 'View user',
        );
    }
}
