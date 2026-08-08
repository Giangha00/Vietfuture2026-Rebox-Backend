<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn ($record): string => 'Photos ('.count(self::normalizeImages($record?->images)).')')
                ->description('Every photo the seller uploaded for this listing. Click to open full size.')
                ->schema([
                    ViewEntry::make('images')
                        ->hiddenLabel()
                        ->getStateUsing(fn ($record): array => self::normalizeImages($record?->images))
                        ->view('filament.products.image-gallery')
                        ->columnSpanFull(),
                ]),

            Section::make('Listing details')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('title')->columnSpanFull(),
                        TextEntry::make('description')
                            ->columnSpanFull()
                            ->prose()
                            ->markdown(false),
                        TextEntry::make('price')
                            ->money('USD'),
                        TextEntry::make('condition')->badge(),
                        TextEntry::make('category.name')->label('Category'),
                        // Use a scalar state key — Filament treats JSON arrays as
                        // lists and would repeat formatStateUsing once per key.
                        TextEntry::make('seller_pickup_summary')
                            ->label('Seller pickup')
                            ->getStateUsing(fn ($record): string => self::formatAddress(
                                $record->seller?->pickup_address
                            ))
                            ->columnSpanFull(),
                        TextEntry::make('seller.full_name')->label('Seller'),
                        TextEntry::make('seller.email')->label('Seller email'),
                        IconEntry::make('accepts_offers')
                            ->label('Accepts offers')
                            ->boolean(),
                        TextEntry::make('created_at')->dateTime()->label('Submitted at'),
                    ]),
                ]),

            Section::make('Moderation')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('moderation_status')
                            ->badge()
                            ->label('Moderation (Approved = listed)')
                            ->color(fn (string $state): string => match ($state) {
                                'approved' => 'success',
                                'rejected' => 'danger',
                                default => 'warning',
                            }),
                        TextEntry::make('status')->badge(),
                        IconEntry::make('is_verified')
                            ->boolean()
                            ->label('Verified badge (extra QC)'),
                        TextEntry::make('moderation_notes')
                            ->label('Moderation notes')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('rejection_reason')
                            ->label('Rejection reason')
                            ->placeholder('—')
                            ->columnSpanFull()
                            ->visible(fn ($record): bool => filled($record->rejection_reason)),
                        TextEntry::make('moderated_at')->dateTime()->placeholder('—'),
                        TextEntry::make('moderator.full_name')
                            ->label('Moderated by')
                            ->placeholder('—'),
                    ]),
                ]),
        ]);
    }

    public static function formatAddress(mixed $address): string
    {
        if (! is_array($address)) {
            return '—';
        }

        $parts = array_filter([
            filled($address['fullName'] ?? null) ? (string) $address['fullName'] : null,
            filled($address['phone'] ?? null) ? (string) $address['phone'] : null,
            filled($address['line1'] ?? null) ? (string) $address['line1'] : null,
            filled($address['line2'] ?? null) ? (string) $address['line2'] : null,
            trim(
                implode(', ', array_filter([
                    filled($address['district'] ?? null) ? (string) $address['district'] : null,
                    filled($address['city'] ?? null) ? (string) $address['city'] : null,
                ])),
                ', '
            ) ?: null,
            filled($address['note'] ?? null) ? '('.(string) $address['note'].')' : null,
        ]);

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    /**
     * @param  mixed  $images
     * @return list<string>
     */
    public static function normalizeImages(mixed $images): array
    {
        if (is_string($images) && $images !== '') {
            $decoded = json_decode($images, true);
            $images = is_array($decoded) ? $decoded : [$images];
        }

        if (! is_array($images)) {
            return [];
        }

        return collect($images)
            ->flatMap(function ($item) {
                if (is_array($item)) {
                    if (isset($item['url']) && is_string($item['url'])) {
                        return [$item['url']];
                    }

                    return array_values($item);
                }

                return [$item];
            })
            ->map(fn ($url) => is_string($url) ? trim($url) : '')
            ->filter(fn ($url) => $url !== '' && (str_starts_with($url, 'http') || str_starts_with($url, '/')))
            ->unique()
            ->values()
            ->all();
    }
}
