<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Filament\Resources\Orders\Support\OrderAdminActions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Order')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('buyer_id')
                            ->relationship('buyer', 'full_name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('shipper_id')
                            ->relationship('shipper', 'full_name')
                            ->searchable()
                            ->preload(),
                        TextInput::make('total_amount')->numeric()->prefix('$')->required(),
                        TextInput::make('platform_fee')->numeric()->prefix('$'),
                        TextInput::make('seller_payout')->numeric()->prefix('$'),
                        TextInput::make('currency')->default('USD')->maxLength(8),
                        Select::make('status')
                            ->options(OrderAdminActions::STATUS_OPTIONS)
                            ->required(),
                        Select::make('payment_status')->options([
                            'unpaid' => 'Unpaid',
                            'pending' => 'Pending',
                            'paid' => 'Paid',
                            'refunded' => 'Refunded',
                            'released' => 'Released',
                        ]),
                        Select::make('escrow_status')->options([
                            'none' => 'None',
                            'held' => 'Held',
                            'released' => 'Released',
                            'refunded' => 'Refunded',
                        ]),
                        Textarea::make('note')->rows(2)->columnSpanFull(),
                        TextInput::make('cancel_reason')->columnSpanFull(),
                    ]),
                ]),

            Section::make('Schedule')
                ->schema([
                    Grid::make(2)->schema([
                        DateTimePicker::make('paid_at'),
                        DateTimePicker::make('assigned_at'),
                        DateTimePicker::make('estimated_pickup_at'),
                        DateTimePicker::make('estimated_delivery_at'),
                        DateTimePicker::make('picked_up_at'),
                        DateTimePicker::make('delivered_at'),
                        DateTimePicker::make('buyer_confirmed_at'),
                        DateTimePicker::make('auto_complete_at'),
                        DateTimePicker::make('completed_at'),
                        DateTimePicker::make('cancelled_at'),
                    ]),
                ]),
        ]);
    }
}
