<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Support\ProductModeration;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('approve')
                ->label('Approve & list')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => $this->record->moderation_status === 'pending')
                ->form([
                    Textarea::make('moderation_notes')
                        ->label('Review checklist notes')
                        ->helperText('Quick notes: photos OK, specs match, condition honest, accessories declared…')
                        ->rows(3)
                        ->default(fn (): string => (string) (
                            $this->record->ai_meta['notes_for_admin']
                            ?? $this->record->ai_meta['draft']['notesForAdmin']
                            ?? $this->record->moderation_notes
                            ?? ''
                        ))
                        ->placeholder('e.g. Photos clear · layout/Hz match · Good condition noted'),
                ])
                ->modalHeading('Approve this product?')
                ->modalDescription('Listing goes live. This does NOT add the Verified badge — use “Mark verified” after extra QC.')
                ->action(function (array $data): void {
                    ProductModeration::approve(
                        $this->record->fresh(),
                        (string) ($data['moderation_notes'] ?? '')
                    );
                    $this->record->refresh();
                }),
            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->moderation_status === 'pending')
                ->form([
                    Textarea::make('rejection_reason')
                        ->label('Rejection reason')
                        ->helperText('This reason will be sent to the seller. They must create a new listing.')
                        ->required()
                        ->minLength(5)
                        ->rows(4)
                        ->placeholder('Explain why this product cannot be listed…'),
                ])
                ->modalHeading('Reject this product?')
                ->modalDescription('The product will not be listed. The seller must create a new product to sell again.')
                ->action(function (array $data): void {
                    ProductModeration::reject($this->record->fresh(), $data['rejection_reason']);
                    $this->record->refresh();
                }),
            Action::make('verify')
                ->label('Mark verified')
                ->icon('heroicon-o-shield-check')
                ->color('info')
                ->visible(fn (): bool => $this->record->moderation_status === 'approved' && ! $this->record->is_verified)
                ->form([
                    Textarea::make('notes')
                        ->label('Verification notes')
                        ->helperText('Why this listing earns the Verified badge (extra photo QC, serial visible, etc.).')
                        ->required()
                        ->minLength(5)
                        ->rows(3),
                ])
                ->modalHeading('Grant Verified badge?')
                ->modalDescription('Verified is extra trust — only after stronger QC than a normal approve.')
                ->action(function (array $data): void {
                    ProductModeration::markVerified($this->record->fresh(), (string) $data['notes']);
                    $this->record->refresh();
                }),
            Action::make('unverify')
                ->label('Remove verified')
                ->icon('heroicon-o-shield-exclamation')
                ->color('warning')
                ->visible(fn (): bool => (bool) $this->record->is_verified)
                ->requiresConfirmation()
                ->action(function (): void {
                    ProductModeration::unverify($this->record->fresh());
                    $this->record->refresh();
                }),
        ];
    }
}
