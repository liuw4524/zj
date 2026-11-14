<?php

namespace App\Filament\Resources\RuleSectionResource\Pages;

use App\Filament\Resources\RuleSectionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRuleSections extends ListRecords
{
    protected static string $resource = RuleSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('增加'),
        ];
    }
}
