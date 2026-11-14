<?php

namespace App\Filament\Resources\SettingResource\Pages;

use App\Filament\Resources\SettingResource;
use App\Support\Money;
use Filament\Resources\Pages\EditRecord;

class EditSetting extends EditRecord
{
    protected static string $resource = SettingResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->getRecord()->key === 'reward_cap_fen') {
            $data['value'] = ((int) $data['value']) / 100;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->getRecord()->key === 'reward_cap_fen') {
            $data['value'] = Money::toFen($data['value']);
        }

        return $data;
    }
}
