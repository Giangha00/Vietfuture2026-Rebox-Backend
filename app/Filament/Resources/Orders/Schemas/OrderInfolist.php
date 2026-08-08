<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Filament\Resources\Orders\Support\OrderAdminActions;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Summary')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('id')->label('Order #'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn ($state) => OrderAdminActions::STATUS_OPTIONS[$state] ?? $state)
                            ->color(fn (string $state): string => match ($state) {
                                'completed' => 'success',
                                'cancelled', 'disputed' => 'danger',
                                'paid', 'seller_confirmed', 'pickup_assigned', 'picked_up', 'out_for_delivery', 'delivered' => 'warning',
                                default => 'gray',
                            }),
                        TextEntry::make('created_at')->dateTime()->label('Created'),
                        TextEntry::make('total_amount')->money('USD')->label('Total'),
                        TextEntry::make('platform_fee')->money('USD'),
                        TextEntry::make('seller_payout')->money('USD'),
                        TextEntry::make('payment_status')->badge(),
                        TextEntry::make('escrow_status')->badge(),
                        TextEntry::make('currency'),
                        TextEntry::make('paid_at')->dateTime()->placeholder('—'),
                        TextEntry::make('paypal_order_id')->label('PayPal order')->placeholder('—')->copyable(),
                        TextEntry::make('paypal_capture_id')->label('PayPal capture')->placeholder('—')->copyable(),
                    ]),
                ]),

            Section::make('People')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('buyer.full_name')->label('Buyer'),
                        TextEntry::make('buyer.email')->label('Buyer email'),
                        TextEntry::make('buyer.phone')->label('Buyer phone')->placeholder('—'),
                        TextEntry::make('shipper.full_name')->label('Shipper')->placeholder('Unassigned'),
                        TextEntry::make('shipper.email')->label('Shipper email')->placeholder('—'),
                        TextEntry::make('shipper.phone')->label('Shipper phone')->placeholder('—'),
                    ]),
                ]),

            Section::make('Items')
                ->schema([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('title')->label('Product'),
                            TextEntry::make('price')->money('USD'),
                            TextEntry::make('product')
                                ->label('Product ID')
                                ->formatStateUsing(fn ($state) => is_array($state) ? ($state['id'] ?? $state['_id'] ?? '—') : ($state ?: '—')),
                            TextEntry::make('seller')
                                ->label('Seller')
                                ->formatStateUsing(function ($state) {
                                    if (is_array($state)) {
                                        return $state['fullName'] ?? $state['full_name'] ?? ($state['id'] ?? '—');
                                    }

                                    return $state ?: '—';
                                }),
                        ])
                        ->columns(4)
                        ->columnSpanFull(),
                ]),

            Section::make('Addresses')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('delivery_address_summary')
                            ->label('Delivery')
                            ->getStateUsing(fn ($record): string => self::formatAddress($record->delivery_address))
                            ->columnSpanFull(),
                        TextEntry::make('pickup_address_summary')
                            ->label('Pickup')
                            ->getStateUsing(fn ($record): string => self::formatAddress($record->pickup_address))
                            ->columnSpanFull(),
                        TextEntry::make('pickupStation.address')
                            ->label('Pickup station')
                            ->formatStateUsing(function ($state, $record) {
                                $station = $record->pickupStation;
                                if (! $station) {
                                    return '—';
                                }

                                return trim(($station->partner_name ?? '').' · '.($station->city ?? '').' — '.($station->address ?? ''), ' ·— ');
                            }),
                        TextEntry::make('note')->placeholder('—'),
                    ]),
                ]),

            Section::make('Timeline')
                ->schema([
                    ViewEntry::make('timeline')
                        ->hiddenLabel()
                        ->view('filament.orders.timeline')
                        ->columnSpanFull(),
                ]),

            Section::make('Dispute')
                ->visible(fn ($record) => filled($record->dispute))
                ->schema([
                    TextEntry::make('dispute.reason')->label('Reason')->columnSpanFull(),
                    TextEntry::make('dispute.resolutionStatus')->label('Resolution status')->placeholder('Open'),
                    TextEntry::make('dispute.resolution')->label('Resolution')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('dispute.createdAt')->label('Opened at')->placeholder('—'),
                    TextEntry::make('dispute.resolvedAt')->label('Resolved at')->placeholder('—'),
                ]),

            Section::make('Schedule')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('assigned_at')->dateTime()->placeholder('—'),
                        TextEntry::make('estimated_pickup_at')->dateTime()->placeholder('—'),
                        TextEntry::make('estimated_delivery_at')->dateTime()->placeholder('—'),
                        TextEntry::make('picked_up_at')->dateTime()->placeholder('—'),
                        TextEntry::make('delivered_at')->dateTime()->placeholder('—'),
                        TextEntry::make('buyer_confirmed_at')->dateTime()->placeholder('—'),
                        TextEntry::make('auto_complete_at')->dateTime()->placeholder('—'),
                        TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                        TextEntry::make('cancelled_at')->dateTime()->placeholder('—'),
                        TextEntry::make('cancel_reason')->placeholder('—')->columnSpanFull(),
                    ]),
                ]),
        ]);
    }

    protected static function formatAddress(mixed $address): string
    {
        if (! is_array($address)) {
            return '—';
        }

        $districtCity = trim(
            implode(', ', array_filter([
                filled($address['district'] ?? null) ? (string) $address['district'] : null,
                filled($address['city'] ?? null) ? (string) $address['city'] : null,
            ])),
            ', '
        );

        $parts = array_filter([
            filled($address['fullName'] ?? null) ? (string) $address['fullName'] : null,
            filled($address['phone'] ?? null) ? (string) $address['phone'] : null,
            filled($address['line1'] ?? null) ? (string) $address['line1'] : null,
            filled($address['line2'] ?? null) ? (string) $address['line2'] : null,
            $districtCity !== '' ? $districtCity : null,
            filled($address['note'] ?? null) ? '('.(string) $address['note'].')' : null,
        ]);

        return $parts === [] ? '—' : implode(' · ', $parts);
    }
}
