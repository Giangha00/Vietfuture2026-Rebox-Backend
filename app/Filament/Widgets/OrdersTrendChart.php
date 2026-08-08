<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class OrdersTrendChart extends ChartWidget
{
    protected ?string $heading = 'Orders trend';

    protected ?string $description = 'New orders over time';

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

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
        [$labels, $values] = $this->series(fn (Carbon $from, Carbon $to) => Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->count());

        return [
            'datasets' => [
                [
                    'label' => 'Orders',
                    'data' => $values,
                    'fill' => 'start',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
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
                $values[] = $aggregator($from, $to);
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
            $values[] = $aggregator($from, $to);
        }

        return [$labels, $values];
    }
}
