<?php

namespace App\Filament\Widgets;

use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class ReboxStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $ordersToday = Order::query()->whereDate('created_at', today())->count();
        $ordersWeek = Order::query()->where('created_at', '>=', now()->subDays(6)->startOfDay())->count();
        $revenuePaid = (float) Order::query()
            ->where(function ($query) {
                $query->whereNotNull('paid_at')
                    ->orWhereIn('payment_status', ['paid', 'completed']);
            })
            ->sum('total_amount');
        $pendingReview = Product::query()->where('moderation_status', 'pending')->count();

        return [
            Stat::make('Users', User::query()->count())
                ->description($this->deltaDescription(User::class))
                ->descriptionIcon('heroicon-m-user-plus')
                ->chart($this->dailyCounts(User::class))
                ->color('primary'),
            Stat::make('Products', Product::query()->count())
                ->description($pendingReview.' pending review')
                ->descriptionIcon('heroicon-m-eye')
                ->chart($this->dailyCounts(Product::class))
                ->color('warning'),
            Stat::make('Orders', Order::query()->count())
                ->description($ordersToday.' today · '.$ordersWeek.' this week')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->chart($this->dailyCounts(Order::class))
                ->color('success'),
            Stat::make('Revenue (paid)', '$'.number_format($revenuePaid, 2))
                ->description(Offer::query()->count().' offers total')
                ->descriptionIcon('heroicon-m-banknotes')
                ->chart($this->dailyRevenue())
                ->color('info'),
        ];
    }

    /**
     * @param  class-string  $model
     * @return list<float>
     */
    protected function dailyCounts(string $model): array
    {
        $start = now()->subDays(6)->startOfDay();
        $rows = $model::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->countBy(fn ($row) => Carbon::parse($row->created_at)->format('Y-m-d'));

        return collect(range(6, 0))
            ->map(fn (int $i) => (float) ($rows[now()->subDays($i)->format('Y-m-d')] ?? 0))
            ->all();
    }

    /**
     * @return list<float>
     */
    protected function dailyRevenue(): array
    {
        $start = now()->subDays(6)->startOfDay();
        $rows = Order::query()
            ->where('created_at', '>=', $start)
            ->where(function ($query) {
                $query->whereNotNull('paid_at')
                    ->orWhereIn('payment_status', ['paid', 'completed']);
            })
            ->get(['created_at', 'total_amount'])
            ->groupBy(fn ($row) => Carbon::parse($row->created_at)->format('Y-m-d'))
            ->map(fn ($group) => (float) $group->sum('total_amount'));

        return collect(range(6, 0))
            ->map(fn (int $i) => (float) ($rows[now()->subDays($i)->format('Y-m-d')] ?? 0))
            ->all();
    }

    /**
     * @param  class-string  $model
     */
    protected function deltaDescription(string $model): string
    {
        $week = $model::query()
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->count();

        return $week.' new this week';
    }
}
