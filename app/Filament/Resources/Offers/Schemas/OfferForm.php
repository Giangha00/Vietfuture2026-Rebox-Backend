<?php

namespace App\Filament\Resources\Offers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OfferForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_id')->relationship('product', 'title')->searchable()->required(),
            Select::make('buyer_id')->relationship('buyer', 'full_name')->searchable()->required(),
            Select::make('seller_id')->relationship('seller', 'full_name')->searchable()->required(),
            TextInput::make('list_price')->numeric()->required(),
            Select::make('discount_percent')->options([
                5 => '5%',
                10 => '10%',
                15 => '15%',
            ])->required(),
            TextInput::make('offer_price')->numeric()->required(),
            Textarea::make('message')->rows(2),
            Select::make('status')->options([
                'pending' => 'Pending',
                'accepted' => 'Accepted',
                'rejected' => 'Rejected',
                'cancelled' => 'Cancelled',
                'expired' => 'Expired',
            ])->required(),
        ]);
    }
}
