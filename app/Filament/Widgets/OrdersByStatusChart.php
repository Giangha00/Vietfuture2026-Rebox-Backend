<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget;

class OrdersByStatusChart extends ChartWidget
{
    protected ?string $heading = 'Orders by status';

    protected ?string $description = 'Current order pipeline';

    protected static ?int $sort = 5;

    protected ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $rows = Order::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->orderByDesc('total')
            ->pluck('total', 'status');

        if ($rows->isEmpty()) {
            return [];
        }

        $palette = [
            '#f59e0b',
            '#3b82f6',
            '#10b981',
            '#8b5cf6',
            '#ef4444',
            '#06b6d4',
            '#f97316',
            '#64748b',
            '#ec4899',
            '#84cc16',
        ];

        $labels = $rows->keys()
            ->map(fn (string $status) => str($status)->replace('_', ' ')->title()->toString())
            ->values()
            ->all();

        $colors = collect($labels)
            ->values()
            ->map(fn ($_, int $i) => $palette[$i % count($palette)])
            ->all();

        return [
            'datasets' => [
                [
                    'label' => 'Orders',
                    'data' => $rows->values()->all(),
                    'backgroundColor' => $colors,
                    'borderWidth' => 0,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
            'scales' => [
                'x' => ['display' => false],
                'y' => ['display' => false],
            ],
        ];
    }
}
