<?php

namespace App\Filament\Resources\Stations\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class StationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('city')->required(),
            TextInput::make('address')->required(),
            TextInput::make('locker_code'),
            Select::make('partner_name')
                ->options([
                    'Circle K' => 'Circle K',
                    'GS25' => 'GS25',
                    'Other' => 'Other',
                ])
                ->required(),
            Toggle::make('is_active')->default(true),
        ]);
    }
}
