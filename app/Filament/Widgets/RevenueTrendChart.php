<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class RevenueTrendChart extends ChartWidget
{
    protected ?string $heading = 'Revenue trend';

    protected ?string $description = 'Paid order totals (USD)';

    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    protected string $color = 'success';

    public ?string $filter = '30d';

    protected function getFilters(): ?array
    {
        return [
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            '12m' => 'Last 12 months',
        ];
    }

    public function updatedFilter(): void
    {
        $this->cachedData = null;
    }

    protected function getData(): array
    {
        [$labels, $values] = $this->series(fn (Carbon $from, Carbon $to) => (float) Order::query()
            ->where(function ($query) {
                $query->whereNotNull('paid_at')
                    ->orWhereIn('payment_status', ['paid', 'completed']);
            })
            ->whereBetween('created_at', [$from, $to])
            ->sum('total_amount'));

        return [
            'datasets' => [
                [
                    'label' => 'Revenue',
                    'data' => $values,
                    'borderRadius' => 4,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @param  callable(Carbon, Carbon): int|float  $aggregator
     * @return array{0: list<string>, 1: list<int|float>}
     */
    protected function series(callable $aggregator): array
    {
        $filter = $this->filter ?? '30d';

        if ($filter === '12m') {
            $labels = [];
            $values = [];

            for ($i = 11; $i >= 0; $i--) {
                $month = now()->subMonths($i);
                $from = $month->copy()->startOfMonth();
                $to = $month->copy()->endOfMonth();
                $labels[] = $month->format('M Y');
                $values[] = round($aggregator($from, $to), 2);
            }

            return [$labels, $values];
        }

        $days = $filter === '7d' ? 6 : 29;
        $labels = [];
        $values = [];

        for ($i = $days; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $from = $day->copy()->startOfDay();
            $to = $day->copy()->endOfDay();
            $labels[] = $day->format('d M');
            $values[] = round($aggregator($from, $to), 2);
        }

        return [$labels, $values];
    }
}
