<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Support\OrderAdminActions;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('items')
                    ->label('Items')
                    ->formatStateUsing(function ($state) {
                        if (! is_array($state) || $state === []) {
                            return '—';
                        }
                        $titles = collect($state)->pluck('title')->filter()->take(2)->all();

                        return implode(', ', $titles).(count($state) > 2 ? '…' : '');
                    })
                    ->wrap()
                    ->limit(40),
                TextColumn::make('buyer.full_name')->label('Buyer')->searchable(),
                TextColumn::make('total_amount')->money('USD')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => OrderAdminActions::STATUS_OPTIONS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'cancelled', 'disputed' => 'danger',
                        'paid', 'seller_confirmed', 'pickup_assigned', 'picked_up', 'out_for_delivery', 'delivered' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('payment_status')->badge()->toggleable(),
                TextColumn::make('escrow_status')->badge()->toggleable(),
                TextColumn::make('shipper.full_name')->label('Shipper')->toggleable(),
                TextColumn::make('paid_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(OrderAdminActions::STATUS_OPTIONS),
                SelectFilter::make('payment_status')->options([
                    'unpaid' => 'Unpaid',
                    'pending' => 'Pending',
                    'paid' => 'Paid',
                    'refunded' => 'Refunded',
                    'released' => 'Released',
                ]),
                SelectFilter::make('escrow_status')->options([
                    'none' => 'None',
                    'held' => 'Held',
                    'released' => 'Released',
                    'refunded' => 'Refunded',
                ]),
            ])
            ->recordUrl(fn ($record) => OrderResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()->label('Track'),
                EditAction::make(),
                Action::make('updateStatus')
                    ->label('Status')
                    ->icon('heroicon-o-arrow-path')
                    ->form([
                        Select::make('status')
                            ->options(OrderAdminActions::STATUS_OPTIONS)
                            ->required(),
                        Textarea::make('note')->required()->minLength(5)->rows(2),
                    ])
                    ->action(fn ($record, array $data) => OrderAdminActions::updateStatus($record, $data['status'], $data['note'])),
                Action::make('assignShipper')
                    ->visible(fn ($record) => in_array($record->status, ['paid', 'seller_confirmed', 'pickup_assigned'], true))
                    ->form([
                        Select::make('shipper_id')
                            ->label('Shipper')
                            ->options(fn () => OrderAdminActions::shipperOptions())
                            ->required(),
                        Textarea::make('note')->rows(2),
                    ])
                    ->action(fn ($record, array $data) => OrderAdminActions::assignShipper(
                        $record,
                        (int) $data['shipper_id'],
                        (string) ($data['note'] ?? '')
                    )),
                Action::make('resolveDispute')
                    ->visible(fn ($record) => $record->status === 'disputed')
                    ->form([
                        Select::make('resolutionStatus')
                            ->options([
                                'completed' => 'Completed (release to seller)',
                                'cancelled' => 'Cancelled / refund',
                                'refunded' => 'Refunded',
                            ])
                            ->required(),
                        Textarea::make('resolution')->required()->minLength(5)->rows(2),
                    ])
                    ->action(fn ($record, array $data) => OrderAdminActions::resolveDispute(
                        $record,
                        $data['resolutionStatus'],
                        $data['resolution']
                    )),
            ]);
    }
}
