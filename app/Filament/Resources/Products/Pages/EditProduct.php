<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Review'),
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return ProductResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
