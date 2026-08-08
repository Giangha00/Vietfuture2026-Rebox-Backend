<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Support\ProductModeration;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('images')
                    ->label('Photos')
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_array($state) ? count($state) : 0)
                    ->color('gray'),
                ImageColumn::make('thumbnail')
                    ->label('Preview')
                    ->square()
                    ->height(56)
                    ->checkFileExistence(false)
                    ->getStateUsing(fn ($record) => is_array($record->images) ? ($record->images[0] ?? null) : null),
                TextColumn::make('title')->searchable()->limit(40)->wrap(),
                TextColumn::make('brand')->searchable()->toggleable(),
                TextColumn::make('price')->money('USD'),
                TextColumn::make('seller.full_name')->label('Seller')->toggleable(),
                TextColumn::make('category.name')->label('Category')->toggleable(),
                TextColumn::make('moderation_status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('status')->badge(),
                IconColumn::make('is_verified')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('moderation_status')->options([
                    'pending' => 'Pending',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                ]),
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'reserved' => 'Reserved',
                    'sold' => 'Sold',
                    'archived' => 'Archived',
                ]),
            ])
            ->recordUrl(fn ($record) => ProductResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()->label('Review'),
                EditAction::make(),
                Action::make('approve')
                    ->visible(fn ($record) => $record->moderation_status === 'pending')
                    ->color('success')
                    ->form([
                        Textarea::make('moderation_notes')
                            ->label('Review notes (optional)')
                            ->rows(2),
                    ])
                    ->modalHeading('Approve & list?')
                    ->modalDescription('Does not grant Verified badge.')
                    ->action(fn ($record, array $data) => ProductModeration::approve(
                        $record,
                        (string) ($data['moderation_notes'] ?? '')
                    )),
                Action::make('reject')
                    ->visible(fn ($record) => $record->moderation_status === 'pending')
                    ->color('danger')
                    ->form([
                        Textarea::make('rejection_reason')
                            ->required()
                            ->minLength(5)
                            ->label('Rejection reason'),
                    ])
                    ->action(fn ($record, array $data) => ProductModeration::reject($record, $data['rejection_reason'])),
                Action::make('verify')
                    ->label('Verify')
                    ->visible(fn ($record) => $record->moderation_status === 'approved' && ! $record->is_verified)
                    ->color('info')
                    ->form([
                        Textarea::make('notes')
                            ->label('Verification notes')
                            ->required()
                            ->minLength(5)
                            ->rows(2),
                    ])
                    ->action(fn ($record, array $data) => ProductModeration::markVerified($record, (string) $data['notes'])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
