<?php
// File: app/Filament/Resources/SubscriberResource/Pages/ListSubscribers.php

declare(strict_types=1);

namespace App\Filament\Resources\SubscriberResource\Pages;

use App\Enums\SubscriberStatus;
use App\Filament\Resources\SubscriberResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListSubscribers extends ListRecords
{
    protected static string $resource = SubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New Subscriber')
                ->icon('heroicon-m-user-plus'),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Subscribers'),

            'active' => Tab::make('Active')
                ->badgeColor('success')
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query->where('status', SubscriberStatus::ACTIVE->value)
                ),

            'expired' => Tab::make('Expired (Walled Garden)')
                ->badgeColor('warning')
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query->where('status', SubscriberStatus::EXPIRED->value)
                ),

            'suspended' => Tab::make('Suspended (Auth Reject)')
                ->badgeColor('danger')
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query->where('status', SubscriberStatus::SUSPENDED->value)
                ),
        ];
    }
}
