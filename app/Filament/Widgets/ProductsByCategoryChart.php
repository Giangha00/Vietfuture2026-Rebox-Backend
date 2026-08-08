<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Filament\Widgets\ChartWidget;

class ProductsByCategoryChart extends ChartWidget
{
    protected ?string $heading = 'Products by category';

    protected ?string $description = 'Listings grouped by category';

    protected static ?int $sort = 4;

    protected ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $rows = Product::query()
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->selectRaw('categories.name as name, COUNT(*) as total')
            ->groupBy('categories.name')
            ->orderByDesc('total')
            ->pluck('total', 'name');

        $palette = [
            '#f59e0b',
            '#10b981',
            '#3b82f6',
            '#8b5cf6',
            '#ef4444',
            '#06b6d4',
            '#f97316',
            '#84cc16',
        ];

        $labels = $rows->keys()->values()->all();
        $colors = collect($labels)
            ->values()
            ->map(fn ($_, int $i) => $palette[$i % count($palette)])
            ->all();

        return [
            'datasets' => [
                [
                    'label' => 'Products',
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
