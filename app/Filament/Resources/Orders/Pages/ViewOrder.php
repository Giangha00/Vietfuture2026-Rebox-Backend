<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Support\OrderAdminActions;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Edit details'),
            Action::make('updateStatus')
                ->label('Update status')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->form([
                    Select::make('status')
                        ->label('New status')
                        ->options(OrderAdminActions::STATUS_OPTIONS)
                        ->required()
                        ->default(fn () => $this->record->status),
                    Textarea::make('note')
                        ->label('Admin note')
                        ->helperText('Shown in the order timeline and sent to buyer/seller.')
                        ->required()
                        ->minLength(5)
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    OrderAdminActions::updateStatus($this->record->fresh(), $data['status'], $data['note']);
                    $this->record->refresh();
                }),
            Action::make('assignShipper')
                ->label('Assign shipper')
                ->icon('heroicon-o-truck')
                ->visible(fn (): bool => in_array($this->record->status, ['paid', 'seller_confirmed', 'pickup_assigned'], true))
                ->form([
                    Select::make('shipper_id')
                        ->label('Shipper')
                        ->options(fn () => OrderAdminActions::shipperOptions())
                        ->searchable()
                        ->required(),
                    Textarea::make('note')->label('Note')->rows(2),
                ])
                ->action(function (array $data): void {
                    OrderAdminActions::assignShipper(
                        $this->record->fresh(),
                        (int) $data['shipper_id'],
                        (string) ($data['note'] ?? '')
                    );
                    $this->record->refresh();
                }),
            Action::make('resolveDispute')
                ->label('Resolve dispute')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === 'disputed')
                ->form([
                    Select::make('resolutionStatus')
                        ->label('Resolution')
                        ->options([
                            'completed' => 'Completed (release escrow to seller)',
                            'cancelled' => 'Cancelled / refund buyer',
                            'refunded' => 'Refunded',
                        ])
                        ->required(),
                    Textarea::make('resolution')->label('Resolution note')->required()->minLength(5)->rows(3),
                ])
                ->action(function (array $data): void {
                    OrderAdminActions::resolveDispute(
                        $this->record->fresh(),
                        $data['resolutionStatus'],
                        $data['resolution']
                    );
                    $this->record->refresh();
                }),
            Action::make('cancelOrder')
                ->label('Cancel order')
                ->color('danger')
                ->visible(fn (): bool => ! in_array($this->record->status, ['completed', 'cancelled'], true))
                ->requiresConfirmation()
                ->form([
                    Textarea::make('note')
                        ->label('Cancel reason')
                        ->required()
                        ->minLength(5)
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    OrderAdminActions::updateStatus($this->record->fresh(), 'cancelled', $data['note']);
                    $this->record->refresh();
                }),
        ];
    }
}
