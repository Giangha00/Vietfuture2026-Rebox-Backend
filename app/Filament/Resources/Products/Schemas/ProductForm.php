<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        $publicBase = rtrim((string) config('rebox.public_base_url', config('app.url')), '/');

        return $schema->components([
            Section::make('Photos')
                ->description('Add, remove, or reorder listing photos.')
                ->schema([
                    FileUpload::make('images')
                        ->label('Product images')
                        ->multiple()
                        ->image()
                        ->reorderable()
                        ->openable()
                        ->downloadable()
                        ->disk('public')
                        ->directory('uploads')
                        ->visibility('public')
                        ->panelLayout('grid')
                        ->imagePreviewHeight('160')
                        ->formatStateUsing(function ($state) use ($publicBase): array {
                            return collect(is_array($state) ? $state : [])
                                ->filter()
                                ->map(function (string $url) use ($publicBase): string {
                                    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                                        $path = parse_url($url, PHP_URL_PATH) ?: '';
                                        if (str_contains($path, '/storage/')) {
                                            return ltrim(Str::after($path, '/storage/'), '/');
                                        }
                                    }

                                    return ltrim(str_replace($publicBase.'/storage/', '', $url), '/');
                                })
                                ->values()
                                ->all();
                        })
                        ->dehydrateStateUsing(function ($state) use ($publicBase): array {
                            return collect(is_array($state) ? $state : [])
                                ->filter()
                                ->map(function (string $path) use ($publicBase): string {
                                    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                                        return $path;
                                    }

                                    return $publicBase.'/storage/'.ltrim($path, '/');
                                })
                                ->values()
                                ->all();
                        })
                        ->columnSpanFull(),
                ]),

            Section::make('Listing details')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('title')->required()->minLength(3)->columnSpanFull(),
                        TextInput::make('brand')->required()->maxLength(80),
                        Textarea::make('description')->required()->rows(5)->minLength(10)->columnSpanFull(),
                        TextInput::make('price')->numeric()->prefix('$')->required(),
                        Select::make('condition')
                            ->options([
                                'Like New' => 'Like New',
                                'Good' => 'Good',
                                'Fair' => 'Fair',
                            ])
                            ->required(),
                        Select::make('category_id')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('seller_id')
                            ->relationship('seller', 'full_name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        \Filament\Forms\Components\KeyValue::make('attributes')
                            ->label('Specs (attributes)')
                            ->keyLabel('Key')
                            ->valueLabel('Value')
                            ->columnSpanFull()
                            ->formatStateUsing(function ($state): array {
                                return collect(is_array($state) ? $state : [])
                                    ->map(function ($value) {
                                        if (is_bool($value)) {
                                            return $value ? 'true' : 'false';
                                        }

                                        return is_scalar($value) ? (string) $value : json_encode($value);
                                    })
                                    ->all();
                            })
                            ->dehydrateStateUsing(function ($state): array {
                                return collect(is_array($state) ? $state : [])
                                    ->map(function ($value) {
                                        if ($value === 'true') {
                                            return true;
                                        }
                                        if ($value === 'false') {
                                            return false;
                                        }
                                        if (is_numeric($value) && ! str_contains((string) $value, '.')) {
                                            return (int) $value;
                                        }

                                        return $value;
                                    })
                                    ->all();
                            })
                            ->helperText('Keys: layout, switch, connectivity, hot_swap, has_receiver, shape, weight_g, size_inch, refresh_hz, panel, resolution.'),
                        Toggle::make('accepts_offers')->label('Accepts offers')->default(true),
                    ]),
                ]),

            Section::make('Moderation & status')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('moderation_status')
                            ->options([
                                'pending' => 'Pending',
                                'approved' => 'Approved (listed)',
                                'rejected' => 'Rejected',
                            ])
                            ->helperText('Approved = live after review. Prefer header actions over editing this manually.')
                            ->default('pending')
                            ->required(),
                        Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'reserved' => 'Reserved',
                                'sold' => 'Sold',
                                'archived' => 'Archived',
                            ])
                            ->default('active')
                            ->required(),
                        Toggle::make('is_verified')
                            ->label('Verified badge')
                            ->helperText('Extra QC trust badge — separate from Approved. Prefer “Mark verified” action.'),
                        TextInput::make('rejection_reason')->columnSpanFull(),
                        Textarea::make('moderation_notes')
                            ->label('Moderation notes')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Internal checklist / verify notes (not shown to buyers).'),
                        DateTimePicker::make('moderated_at')->disabled()->dehydrated(false),
                        Select::make('moderated_by')
                            ->relationship('moderator', 'full_name')
                            ->disabled()
                            ->dehydrated(false)
                            ->label('Moderated by'),
                    ]),
                ]),
        ]);
    }
}
