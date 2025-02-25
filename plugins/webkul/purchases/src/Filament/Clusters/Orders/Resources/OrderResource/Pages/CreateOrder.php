<?php

namespace Webkul\Purchase\Filament\Clusters\Orders\Resources\OrderResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Webkul\Purchase\Enums;
use Webkul\Purchase\Filament\Clusters\Orders\Resources\OrderResource;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotification(): Notification
    {
        return Notification::make()
            ->success()
            ->title(__('purchases::filament/clusters/orders/resources/order/pages/create-order.notification.title'))
            ->body(__('purchases::filament/clusters/orders/resources/order/pages/create-order.notification.body'));
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['creator_id'] = Auth::id();

        $data['state'] ??= Enums\OrderState::DRAFT;

        return $data;
    }

    protected function afterCreate(): void
    {
        OrderResource::collectTotals($this->getRecord());
    }
}
