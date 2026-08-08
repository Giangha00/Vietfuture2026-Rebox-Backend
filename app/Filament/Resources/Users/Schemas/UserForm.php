<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('full_name')->required()->label('Full name'),
            TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            TextInput::make('phone')->required()->maxLength(20),
            TextInput::make('password')
                ->password()
                ->dehydrated(fn ($state) => filled($state))
                ->required(fn (string $operation) => $operation === 'create'),
            Select::make('role')
                ->label('App role')
                ->helperText('Marketplace role used by the API (user / shipper / admin).')
                ->options([
                    'user' => 'User',
                    'admin' => 'Admin',
                    'shipper' => 'Shipper',
                ])
                ->required(),
            Select::make('roles')
                ->label('Filament roles')
                ->helperText('RBAC roles for admin panel access & permissions (Shield).')
                ->relationship('roles', 'name')
                ->multiple()
                ->preload()
                ->searchable(),
            TextInput::make('avatar_url')->label('Avatar URL'),
            Textarea::make('bio')->rows(3)->maxLength(280),
            Toggle::make('email_verified')->label('Email verified'),
        ]);
    }
}
