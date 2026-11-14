<?php

namespace App\Filament\Resources\SupportOrderResource\Pages;

use App\Filament\Resources\SupportOrderResource;
use App\Models\SupportOrder;
use App\Support\Money;
use Filament\Resources\Pages\ListRecords;

class ListSupportOrders extends ListRecords
{
    protected static string $resource = SupportOrderResource::class;

    public function getSubheading(): ?string
    {
        $today = SupportOrder::query()->whereDate('paid_at', today());
        $count = (clone $today)->count();
        $amount = (int) (clone $today)->sum('amount');

        return '今日 '.$count.' 笔，'.Money::yuan($amount);
    }
}
