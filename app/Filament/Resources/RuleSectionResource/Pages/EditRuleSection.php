<?php

namespace App\Filament\Resources\RuleSectionResource\Pages;

use App\Filament\Resources\RuleSectionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRuleSection extends EditRecord
{
    protected static string $resource = RuleSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('删除'),
        ];
    }
}
