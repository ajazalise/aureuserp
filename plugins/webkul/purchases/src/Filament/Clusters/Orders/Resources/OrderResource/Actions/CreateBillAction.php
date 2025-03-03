<?php

namespace Webkul\Purchase\Filament\Clusters\Orders\Resources\OrderResource\Actions;

use Filament\Actions\Action;
use Livewire\Component;
use Webkul\Purchase\Enums\OrderState;
use Filament\Notifications\Notification;
use Webkul\Account\Models\Move as AccountMove;
use Webkul\Account\Models\Journal as AccountJournal;
use Webkul\Purchase\Models\Order;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Enums as AccountEnums;

class CreateBillAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'purchases.orders.create-bill';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('purchases::filament/clusters/orders/resources/order/actions/create-bill.label'))
            ->color(function(Order $record): string {
                if ($record->qty_to_invoice == 0) {
                    return 'gray';
                }

                return 'primary';
            })
            ->action(function (Order $record, Component $livewire): void {
                if ($record->qty_to_invoice == 0) {
                    Notification::make()
                        ->title(__('purchases::filament/clusters/orders/resources/order/actions/create-bill.action.notification.warning.title'))
                        ->body(__('purchases::filament/clusters/orders/resources/order/actions/create-bill.action.notification.warning.body'))
                        ->warning()
                        ->send();

                    return;
                }

                $livewire->updateForm();

                Notification::make()
                    ->title(__('purchases::filament/clusters/orders/resources/order/actions/create-bill.action.notification.success.title'))
                    ->body(__('purchases::filament/clusters/orders/resources/order/actions/create-bill.action.notification.success.body'))
                    ->success()
                    ->send();
            })
            ->visible(fn () => in_array($this->getRecord()->state, [
                OrderState::PURCHASE,
                OrderState::DONE,
            ]));
    }

    private function createAccountMove($record): void
    {
        $accountMove = AccountMove::create([
            'state' => AccountEnums\MoveState::DRAFT,
            'move_type' => AccountEnums\MoveType::IN_INVOICE,
            'payment_state' => AccountEnums\PaymentStatus::NOT_PAID,
            'invoice_partner_display_name' => $record->partner->name,
            'invoice_origin' => $record->name,
            'date' => now(),
            'invoice_date_due' => now(),
            'invoice_currency_rate' => 1,
            'journal_id' => AccountJournal::where('code', AccountEnums\JournalType::PURCHASE)->first()?->id,
            'company_id' => $record->company_id,
            'currency_id' => $record->currency_id,
            'invoice_payment_term_id' => $record->payment_term_id,
            'partner_id' => $record->partner_id,
            'commercial_partner_id' => $record->partner_id,
            'partner_shipping_id' => $record->partner_shipping_id,
            // 'partner_bank_id' => $record->partner_bank_id,//TODO: add partner bank id
            'fiscal_position_id' => $record->fiscal_position_id,
            // 'preferred_payment_method_line_id' => 1,
            'creator_id' => Auth::id(),
        ]);

        $record->accountMoves()->attach($accountMove->id);

        foreach ($record->lines as $line) {
            $this->createAccountMoveLine($accountMove, $line);
        }
    }

    private function createAccountMoveLine($accountMove, $orderLine): void
    {
        $accountMoveLine = $accountMove->lines()->create([
            'state' => AccountEnums\MoveState::DRAFT,
            'name' => $orderLine->name,
            'display_type' => AccountEnums\DisplayType::PRODUCT,
            'date' => $accountMove->date,
            'quantity' => $orderLine->qty_to_invoice,
            'price_unit' => $orderLine->price_unit,
            'discount' => $orderLine->discount,
            'journal_id' => $accountMove->journal_id,
            'company_id' => $accountMove->company_id,
            'currency_id' => $accountMove->currency_id,
            'company_currency_id' => $accountMove->currency_id,
            'partner_id' => $accountMove->partner_id,
            'product_id' => $orderLine->product_id,
            'product_uom_id' => $orderLine->uom_id,
            'purchase_order_line_id' => $orderLine->id,
        ]);

        $accountMoveLine->taxes()->sync($orderLine->taxes->pluck('id'));
    }
}
