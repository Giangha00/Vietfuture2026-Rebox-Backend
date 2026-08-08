<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Track order'),
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return OrderResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
