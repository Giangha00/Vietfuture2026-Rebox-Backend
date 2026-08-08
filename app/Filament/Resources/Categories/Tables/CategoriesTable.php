<?php

namespace App\Filament\Resources\Categories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable()->sortable(),
                TextColumn::make('icon')->searchable()->toggleable(),
                TextColumn::make('products_count')
                    ->counts('products')
                    ->label('Products')
                    ->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('name')
                    ->label('Name')
                    ->schema([
                        TextInput::make('name')
                            ->label('Name contains')
                            ->placeholder('Search name…'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            filled($data['name'] ?? null),
                            fn (Builder $q) => $q->where('name', 'like', '%'.$data['name'].'%')
                        );
                    }),
                Filter::make('slug')
                    ->label('Slug')
                    ->schema([
                        TextInput::make('slug')
                            ->label('Slug contains')
                            ->placeholder('Search slug…'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            filled($data['slug'] ?? null),
                            fn (Builder $q) => $q->where('slug', 'like', '%'.$data['slug'].'%')
                        );
                    }),
                SelectFilter::make('icon')
                    ->label('Icon')
                    ->options(fn () => \App\Models\Category::query()
                        ->whereNotNull('icon')
                        ->where('icon', '!=', '')
                        ->distinct()
                        ->orderBy('icon')
                        ->pluck('icon', 'icon')
                        ->all())
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
