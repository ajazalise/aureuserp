<?php

namespace Webkul\Account\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Components\TextEntry\TextEntrySize;
use Filament\Infolists\Infolist;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\FontWeight;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Enums\AutoPost;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Enums\TypeTaxUse;
use Webkul\Account\Livewire\InvoiceSummary;
use Webkul\Account\Models\Move as AccountMove;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Partner;
use Webkul\Account\Filament\Resources\BillResource\Pages;
use Webkul\Field\Filament\Forms\Components\ProgressStepper;
use Webkul\Invoice\Filament\Clusters\Customer\Resources\InvoiceResource;
use Webkul\Invoice\Models\Product;
use Webkul\Invoice\Settings;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\UOM;
use Webkul\Account\Services\MoveLineCalculationService;

class BillResource extends Resource
{
    protected static ?string $model = AccountMove::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                ProgressStepper::make('state')
                    ->hiddenLabel()
                    ->inline()
                    ->options(MoveState::class)
                    ->default(MoveState::DRAFT->value)
                    ->columnSpan('full')
                    ->disabled()
                    ->live()
                    ->reactive(),
                Forms\Components\Section::make(__('purchases::filament/clusters/orders/resources/order.form.sections.general.title'))
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('payment_state')
                                ->icon(fn($record) => PaymentState::from($record->payment_state)->getIcon())
                                ->color(fn($record) => PaymentState::from($record->payment_state)->getColor())
                                ->visible(fn($record) => $record && in_array($record->payment_state, [PaymentState::PAID->value, PaymentState::REVERSED->value]))
                                ->label(fn($record) => PaymentState::from($record->payment_state)->getLabel())
                                ->size(ActionSize::ExtraLarge->value),
                        ]),
                        Forms\Components\Group::make()
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('Vendor Bill'))
                                    ->required()
                                    ->maxLength(255)
                                    ->extraInputAttributes(['style' => 'font-size: 1.5rem;height: 3rem;'])
                                    ->placeholder('BILL/2025/00001')
                                    ->default(fn() => AccountMove::generateNextInvoiceAndCreditNoteNumber('BILL'))
                                    ->unique(
                                        table: 'accounts_account_moves',
                                        column: 'name',
                                        ignoreRecord: true,
                                    )
                                    ->columnSpan(1)
                                    ->disabled(fn($record) => $record && in_array($record->state, [MoveState::POSTED->value, MoveState::CANCEL->value])),
                            ])->columns(2),
                        Forms\Components\Group::make()
                            ->schema([
                                Forms\Components\Group::make()
                                    ->schema([
                                        Forms\Components\Select::make('partner_id')
                                            ->label(__('Vendor'))
                                            ->relationship(
                                                'partner',
                                                'name',
                                            )
                                            ->searchable()
                                            ->preload()
                                            ->live()
                                            ->disabled(fn($record) => $record && in_array($record->state, [MoveState::POSTED->value, MoveState::CANCEL->value])),
                                        Forms\Components\Placeholder::make('partner_address')
                                            ->hiddenLabel()
                                            ->visible(
                                                fn(Get $get) => Partner::with('addresses')->find($get('partner_id'))?->addresses->isNotEmpty()
                                            )
                                            ->content(function (Get $get) {
                                                $partner = Partner::with('addresses.state', 'addresses.country')->find($get('partner_id'));

                                                if (
                                                    ! $partner
                                                    || $partner->addresses->isEmpty()
                                                ) {
                                                    return null;
                                                }

                                                $address = $partner->addresses->first();

                                                return sprintf(
                                                    "%s\n%s%s\n%s, %s %s\n%s",
                                                    $address->name ?? '',
                                                    $address->street1 ?? '',
                                                    $address->street2 ? ', ' . $address->street2 : '',
                                                    $address->city ?? '',
                                                    $address->state ? $address->state->name : '',
                                                    $address->zip ?? '',
                                                    $address->country ? $address->country->name : ''
                                                );
                                            }),
                                    ]),
                                Forms\Components\DatePicker::make('invoice_date')
                                    ->label(__('Bill Date'))
                                    ->default(now())
                                    ->native(false)
                                    ->disabled(fn($record) => $record && in_array($record->state, [MoveState::POSTED->value, MoveState::CANCEL->value])),
                                Forms\Components\TextInput::make('reference')
                                    ->label(__('Bill Reference'))
                                    ->disabled(fn($record) => $record && in_array($record->state, [MoveState::POSTED->value, MoveState::CANCEL->value])),
                                Forms\Components\DatePicker::make('date')
                                    ->label(__('Accounting Date'))
                                    ->default(now())
                                    ->native(false)
                                    ->disabled(fn($record) => $record && in_array($record->state, [MoveState::POSTED->value, MoveState::CANCEL->value])),
                                Forms\Components\TextInput::make('payment_reference')
                                    ->label(__('Payment Reference'))
                                    ->disabled(fn($record) => $record && in_array($record->state, [MoveState::POSTED->value, MoveState::CANCEL->value])),
                                Forms\Components\Select::make('partner_bank_id')
                                    ->relationship('partnerBank', 'account_number')
                                    ->searchable()
                                    ->preload()
                                    ->label(__('Recipient Bank'))
                                    ->createOptionForm(fn($form) => BankAccountResource::form($form))
                                    ->disabled(fn($record) => $record && in_array($record->state, [MoveState::POSTED->value, MoveState::CANCEL->value])),
                                Forms\Components\DatePicker::make('invoice_date_due')
                                    ->required()
                                    ->default(now())
                                    ->native(false)
                                    ->live()
                                    ->hidden(fn(Get $get) => $get('invoice_payment_term_id') !== null)
                                    ->label(__('Due Date')),
                                Forms\Components\Select::make('invoice_payment_term_id')
                                    ->relationship('invoicePaymentTerm', 'name')
                                    ->required(fn(Get $get) => $get('invoice_date_due') === null)
                                    ->live()
                                    ->searchable()
                                    ->preload()
                                    ->label(__('Payment Term')),
                            ])->columns(2),
                    ]),
                Forms\Components\Tabs::make()
                    ->schema([
                        Forms\Components\Tabs\Tab::make(__('Invoice Lines'))
                            ->icon('heroicon-o-list-bullet')
                            ->schema([
                                static::getProductRepeater(),
                                Forms\Components\Livewire::make(InvoiceSummary::class, function (Forms\Get $get) {
                                    return [
                                        'currency' => Currency::find($get('currency_id')),
                                        'products' => $get('products'),
                                    ];
                                })
                                    ->live()
                                    ->reactive(),
                            ]),
                        Forms\Components\Tabs\Tab::make(__('Other Information'))
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                Forms\Components\Fieldset::make('Accounting')
                                    ->schema([
                                        Forms\Components\Select::make('invoice_incoterm_id')
                                            ->relationship('invoiceIncoterm', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->label(__('Incoterm')),
                                        Forms\Components\TextInput::make('incoterm_location')
                                            ->label(__('Incoterm Location')),
                                    ]),
                                Forms\Components\Fieldset::make('Secured')
                                    ->schema([
                                        Forms\Components\Select::make('preferred_payment_method_line_id')
                                            ->relationship('paymentMethodLine', 'name')
                                            ->preload()
                                            ->searchable()
                                            ->label(__('Payment Method')),
                                        Forms\Components\Select::make('auto_post')
                                            ->options(AutoPost::class)
                                            ->default(AutoPost::NO->value)
                                            ->label(__('Auto Post'))
                                            ->disabled(fn($record) => $record && in_array($record->state, [MoveState::POSTED->value, MoveState::CANCEL->value])),
                                        Forms\Components\Toggle::make('checked')
                                            ->inline(false)
                                            ->label(__('Checked')),
                                    ]),
                                Forms\Components\Fieldset::make('Additional Information')
                                    ->schema([
                                        Forms\Components\Select::make('company_id')
                                            ->label(__('Company'))
                                            ->relationship('company', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->default(Auth::user()->default_company_id),
                                        Forms\Components\Select::make('currency_id')
                                            ->label(__('Currency'))
                                            ->relationship('currency', 'name')
                                            ->required()
                                            ->searchable()
                                            ->preload()
                                            ->live()
                                            ->reactive()
                                            ->default(Auth::user()->defaultCompany?->currency_id),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make(__('Term & Conditions'))
                            ->icon('heroicon-o-clipboard-document-list')
                            ->schema([
                                Forms\Components\RichEditor::make('narration')
                                    ->hiddenLabel(),
                            ]),
                    ])
                    ->persistTabInQueryString(),
            ])
            ->columns('full');
    }

    public static function table(Table $table): Table
    {
        return InvoiceResource::table($table);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make(__('purchases::filament/clusters/orders/resources/order.form.sections.general.title'))
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Infolists\Components\Actions::make([
                            Infolists\Components\Actions\Action::make('payment_state')
                                ->icon(fn($record) => PaymentState::from($record->payment_state)->getIcon())
                                ->color(fn($record) => PaymentState::from($record->payment_state)->getColor())
                                ->visible(fn($record) => $record && in_array($record->payment_state, [PaymentState::PAID->value, PaymentState::REVERSED->value]))
                                ->label(fn($record) => PaymentState::from($record->payment_state)->getLabel())
                                ->size(ActionSize::ExtraLarge->value),
                        ]),
                        Infolists\Components\Grid::make()
                            ->schema([
                                Infolists\Components\TextEntry::make('name')
                                    ->placeholder('-')
                                    ->label(__('Customer Invoice'))
                                    ->icon('heroicon-o-document')
                                    ->weight('bold')
                                    ->size(TextEntrySize::Large),
                            ])->columns(2),
                        Infolists\Components\Grid::make()
                            ->schema([
                                Infolists\Components\TextEntry::make('partner.name')
                                    ->placeholder('-')
                                    ->label(__('Customer'))
                                    ->visible(fn($record) => $record->partner_id !== null)
                                    ->icon('heroicon-o-user'),
                                Infolists\Components\TextEntry::make('invoice_partner_display_name')
                                    ->placeholder('-')
                                    ->label(__('Customer'))
                                    ->visible(fn($record) => $record->partner_id === null)
                                    ->icon('heroicon-o-user'),
                                Infolists\Components\TextEntry::make('invoice_date')
                                    ->placeholder('-')
                                    ->label(__('Invoice Date'))
                                    ->icon('heroicon-o-calendar')
                                    ->date(),
                                Infolists\Components\TextEntry::make('invoice_date_due')
                                    ->placeholder('-')
                                    ->icon('heroicon-o-clock')
                                    ->date(),
                                Infolists\Components\TextEntry::make('invoicePaymentTerm.name')
                                    ->placeholder('-')
                                    ->label(__('Payment Term'))
                                    ->icon('heroicon-o-calendar-days'),
                            ])->columns(2),
                    ]),
                Infolists\Components\Tabs::make()
                    ->columnSpan('full')
                    ->tabs([
                        Infolists\Components\Tabs\Tab::make(__('Invoice Lines'))
                            ->icon('heroicon-o-list-bullet')
                            ->schema([
                                Infolists\Components\RepeatableEntry::make('lines')
                                    ->hiddenLabel()
                                    ->schema([
                                        Infolists\Components\TextEntry::make('product.name')
                                            ->placeholder('-')
                                            ->label(__('Product'))
                                            ->icon('heroicon-o-cube'),
                                        Infolists\Components\TextEntry::make('quantity')
                                            ->placeholder('-')
                                            ->label(__('Quantity'))
                                            ->icon('heroicon-o-hashtag'),
                                        Infolists\Components\TextEntry::make('uom.name')
                                            ->placeholder('-')
                                            ->visible(fn(Settings\ProductSettings $settings) => $settings->enable_uom)
                                            ->label(__('Unit of Measure'))
                                            ->icon('heroicon-o-scale'),
                                        Infolists\Components\TextEntry::make('price_unit')
                                            ->placeholder('-')
                                            ->label(__('Unit Price'))
                                            ->icon('heroicon-o-currency-dollar')
                                            ->money(fn($record) => $record->currency->name),
                                        Infolists\Components\TextEntry::make('discount')
                                            ->placeholder('-')
                                            ->label(__('Discount'))
                                            ->icon('heroicon-o-tag')
                                            ->suffix('%'),
                                        Infolists\Components\TextEntry::make('taxes.name')
                                            ->badge()
                                            ->state(function ($record): array {
                                                return $record->taxes->map(fn($tax) => [
                                                    'name' => $tax->name,
                                                ])->toArray();
                                            })
                                            ->icon('heroicon-o-receipt-percent')
                                            ->formatStateUsing(fn($state) => $state['name'])
                                            ->placeholder('-')
                                            ->weight(FontWeight::Bold),
                                        Infolists\Components\TextEntry::make('price_subtotal')
                                            ->placeholder('-')
                                            ->label(__('Subtotal'))
                                            ->icon('heroicon-o-calculator')
                                            ->money(fn($record) => $record->currency->name),
                                        Infolists\Components\TextEntry::make('price_total')
                                            ->placeholder('-')
                                            ->label(__('Total'))
                                            ->icon('heroicon-o-banknotes')
                                            ->money(fn($record) => $record->currency->symbol)
                                            ->weight('bold'),
                                    ])->columns(5),
                                Infolists\Components\Livewire::make(InvoiceSummary::class, function ($record) {
                                    return [
                                        'currency' => $record->currency,
                                        'products' => $record->lines->map(function ($item) {
                                            return [
                                                ...$item->toArray(),
                                                'taxes' => $item->taxes->pluck('id')->toArray() ?? [],
                                            ];
                                        })->toArray(),
                                    ];
                                }),
                            ]),
                        Infolists\Components\Tabs\Tab::make(__('Other Information'))
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                Infolists\Components\Section::make('Invoice')
                                    ->icon('heroicon-o-document')
                                    ->schema([
                                        Infolists\Components\Grid::make()
                                            ->schema([
                                                Infolists\Components\TextEntry::make('reference')
                                                    ->placeholder('-')
                                                    ->label(__('Customer Reference'))
                                                    ->icon('heroicon-o-hashtag'),
                                                Infolists\Components\TextEntry::make('invoiceUser.name')
                                                    ->placeholder('-')
                                                    ->label(__('Sales Person'))
                                                    ->icon('heroicon-o-user'),
                                                Infolists\Components\TextEntry::make('partnerBank.account_number')
                                                    ->placeholder('-')
                                                    ->label(__('Recipient Bank'))
                                                    ->icon('heroicon-o-building-library'),
                                                Infolists\Components\TextEntry::make('payment_reference')
                                                    ->placeholder('-')
                                                    ->label(__('Payment Reference'))
                                                    ->icon('heroicon-o-identification'),
                                                Infolists\Components\TextEntry::make('delivery_date')
                                                    ->placeholder('-')
                                                    ->label(__('Delivery Date'))
                                                    ->icon('heroicon-o-truck')
                                                    ->date(),
                                            ])->columns(2),
                                    ]),
                                Infolists\Components\Section::make('Accounting')
                                    ->icon('heroicon-o-calculator')
                                    ->schema([
                                        Infolists\Components\Grid::make()
                                            ->schema([
                                                Infolists\Components\TextEntry::make('invoiceIncoterm.name')
                                                    ->placeholder('-')
                                                    ->label(__('Incoterm'))
                                                    ->icon('heroicon-o-globe-alt'),
                                                Infolists\Components\TextEntry::make('incoterm_location')
                                                    ->placeholder('-')
                                                    ->label(__('Incoterm Address'))
                                                    ->icon('heroicon-o-map-pin'),
                                                Infolists\Components\TextEntry::make('paymentMethodLine.name')
                                                    ->placeholder('-')
                                                    ->label(__('Payment Method'))
                                                    ->icon('heroicon-o-credit-card'),
                                                Infolists\Components\TextEntry::make('auto_post')
                                                    ->placeholder('-')
                                                    ->label(__('Auto Post'))
                                                    ->icon('heroicon-o-arrow-path')
                                                    ->formatStateUsing(fn(string $state): string => AutoPost::from($state)->getLabel()),
                                                Infolists\Components\IconEntry::make('checked')
                                                    ->label(__('Checked'))
                                                    ->icon('heroicon-o-check-circle')
                                                    ->boolean(),
                                            ])->columns(2),
                                    ]),
                                Infolists\Components\Section::make('Marketing')
                                    ->icon('heroicon-o-megaphone')
                                    ->schema([
                                        Infolists\Components\Grid::make()
                                            ->schema([
                                                Infolists\Components\TextEntry::make('campaign.name')
                                                    ->placeholder('-')
                                                    ->label(__('Campaign'))
                                                    ->icon('heroicon-o-presentation-chart-line'),
                                                Infolists\Components\TextEntry::make('medium.name')
                                                    ->placeholder('-')
                                                    ->label(__('Medium'))
                                                    ->icon('heroicon-o-device-phone-mobile'),
                                                Infolists\Components\TextEntry::make('source.name')
                                                    ->placeholder('-')
                                                    ->label(__('Source'))
                                                    ->icon('heroicon-o-link'),
                                            ])->columns(2),
                                    ]),
                            ]),
                    ])
                    ->persistTabInQueryString(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBills::route('/'),
            'create' => Pages\CreateBill::route('/create'),
            'edit'   => Pages\EditBill::route('/{record}/edit'),
            'view'   => Pages\ViewBill::route('/{record}'),
        ];
    }


    public static function getProductRepeater(): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make('products')
            ->relationship('lines')
            ->hiddenLabel()
            ->live()
            ->reactive()
            ->label(__('Products'))
            ->addActionLabel(__('Add Product'))
            ->collapsible()
            ->defaultItems(0)
            ->itemLabel(fn(array $state): ?string => $state['name'] ?? null)
            ->deleteAction(fn(Forms\Components\Actions\Action $action) => $action->requiresConfirmation())
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Grid::make(4)
                            ->schema([
                                Forms\Components\Select::make('product_id')
                                    ->label(__('Product'))
                                    ->relationship('product', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->dehydrated()
                                    ->disabled(fn($record) => $record && in_array($record->parent_state, [MoveState::POSTED->value, MoveState::CANCEL->value]))
                                    ->afterStateUpdated(fn(Forms\Set $set, Forms\Get $get) => static::afterProductUpdated($set, $get))
                                    ->required(),
                                Forms\Components\TextInput::make('quantity')
                                    ->label(__('Quantity'))
                                    ->required()
                                    ->default(1)
                                    ->numeric()
                                    ->live()
                                    ->dehydrated()
                                    ->disabled(fn($record) => $record && in_array($record->parent_state, [MoveState::POSTED->value, MoveState::CANCEL->value]))
                                    ->afterStateUpdated(fn(Forms\Set $set, Forms\Get $get) => static::afterProductQtyUpdated($set, $get)),
                                Forms\Components\Select::make('uom_id')
                                    ->label(__('Unit'))
                                    ->relationship(
                                        'uom',
                                        'name',
                                        fn($query) => $query->where('category_id', 1)->orderBy('id'),
                                    )
                                    ->required()
                                    ->live()
                                    ->selectablePlaceholder(false)
                                    ->dehydrated()
                                    ->disabled(fn($record) => $record && in_array($record->parent_state, [MoveState::POSTED->value, MoveState::CANCEL->value]))
                                    ->afterStateUpdated(fn(Forms\Set $set, Forms\Get $get) => static::afterUOMUpdated($set, $get))
                                    ->visible(fn(Settings\ProductSettings $settings) => $settings->enable_uom),
                                Forms\Components\Select::make('taxes')
                                    ->label(__('Taxes'))
                                    ->relationship(
                                        'taxes',
                                        'name',
                                        function (Builder $query) {
                                            return $query->where('type_tax_use', TypeTaxUse::SALE->value);
                                        },
                                    )
                                    ->searchable()
                                    ->multiple()
                                    ->preload()
                                    ->dehydrated()
                                    ->disabled(fn($record) => $record && in_array($record->parent_state, [MoveState::POSTED->value, MoveState::CANCEL->value]))
                                    ->afterStateHydrated(fn(Forms\Get $get, Forms\Set $set) => self::calculateLineTotals($set, $get))
                                    ->afterStateUpdated(fn(Forms\Get $get, Forms\Set $set, $state) => self::calculateLineTotals($set, $get))
                                    ->live(),
                                Forms\Components\TextInput::make('discount')
                                    ->label(__('Discount Percentage'))
                                    ->numeric()
                                    ->default(0)
                                    ->live()
                                    ->dehydrated()
                                    ->disabled(fn($record) => $record && in_array($record->parent_state, [MoveState::POSTED->value, MoveState::CANCEL->value]))
                                    ->afterStateUpdated(fn(Forms\Set $set, Forms\Get $get) => self::calculateLineTotals($set, $get)),
                                Forms\Components\TextInput::make('price_unit')
                                    ->label(__('Unit Price'))
                                    ->numeric()
                                    ->default(0)
                                    ->required()
                                    ->live()
                                    ->dehydrated()
                                    ->disabled(fn($record) => $record && in_array($record->parent_state, [MoveState::POSTED->value, MoveState::CANCEL->value]))
                                    ->afterStateUpdated(fn(Forms\Set $set, Forms\Get $get) => self::calculateLineTotals($set, $get)),
                                Forms\Components\TextInput::make('price_subtotal')
                                    ->label(__('Sub Total'))
                                    ->default(0)
                                    ->dehydrated()
                                    ->disabled(fn($record) => $record && in_array($record->parent_state, [MoveState::POSTED->value, MoveState::CANCEL->value])),
                                Forms\Components\Hidden::make('product_uom_qty')
                                    ->default(0),
                                Forms\Components\Hidden::make('price_tax')
                                    ->default(0),
                                Forms\Components\Hidden::make('price_total')
                                    ->default(0),
                            ]),
                    ])
                    ->columns(2),
            ])
            ->mutateRelationshipDataBeforeCreateUsing(fn(array $data, $record, $livewire) => static::mutateProductRelationship($data, $record, $livewire))
            ->mutateRelationshipDataBeforeSaveUsing(fn(array $data, $record, $livewire) => static::mutateProductRelationship($data, $record, $livewire));
    }

    public static function mutateProductRelationship(array $data, $record, $livewire): array
    {
        $data['product_id'] ??= $record->product_id;
        $data['quantity'] ??= $record->quantity;
        $data['uom_id'] ??= $record->uom_id;
        $data['price_subtotal'] ??= $record->price_subtotal;
        $data['discount'] ??= $record->discount;
        $data['discount_date'] ??= $record->discount_date;

        $product = Product::find($data['product_id']);

        $user = Auth::user();

        $data = array_merge($data, [
            'name'                  => $product->name,
            'quantity'              => $data['quantity'],
            'uom_id'                => $data['uom_id'] ?? $product->uom_id,
            'currency_id'           => ($livewire->data['currency_id'] ?? $record->currency_id) ?? $user->defaultCompany->currency_id,
            'partner_id'            => $record->partner_id,
            'creator_id'            => $user->id,
            'company_id'            => $user->default_company_id,
            'company_currency_id'   => $user->defaultCompany->currency_id ?? $record->currency_id,
            'commercial_partner_id' => $livewire->record->partner_id,
            'display_type'          => 'product',
            'sort'                  => MoveLine::max('sort') + 1,
            'parent_state'          => $livewire->record->state ?? MoveState::DRAFT->value,
            'move_name'             => $livewire->record->name,
            'debit'                 => floatval($data['price_subtotal']),
            'credit'                => 0.00,
            'balance'               => floatval($data['price_subtotal']),
            'amount_currency'       => floatval($data['price_subtotal']),
        ]);

        if ($data['discount'] > 0) {
            $data['discount_date'] = now();
        } else {
            $data['discount_date'] = null;
        }

        return $data;
    }

    private static function afterProductUpdated(Forms\Set $set, Forms\Get $get): void
    {
        if (! $get('product_id')) {
            return;
        }

        $product = Product::find($get('product_id'));

        $set('uom_id', $product->uom_id);

        $priceUnit = static::calculateUnitPrice($get('uom_id'), $product->cost ?? $product->price);

        $set('price_unit', round($priceUnit, 2));

        $set('taxes', $product->productTaxes->pluck('id')->toArray());

        $uomQuantity = static::calculateUnitQuantity($get('uom_id'), $get('quantity'));

        $set('product_uom_qty', round($uomQuantity, 2));

        self::calculateLineTotals($set, $get);
    }

    private static function afterProductQtyUpdated(Forms\Set $set, Forms\Get $get): void
    {
        if (! $get('product_id')) {
            return;
        }

        $uomQuantity = static::calculateUnitQuantity($get('uom_id'), $get('quantity'));

        $set('product_uom_qty', round($uomQuantity, 2));

        self::calculateLineTotals($set, $get);
    }

    private static function afterUOMUpdated(Forms\Set $set, Forms\Get $get): void
    {
        if (! $get('product_id')) {
            return;
        }

        $uomQuantity = static::calculateUnitQuantity($get('uom_id'), $get('quantity'));

        $set('product_uom_qty', round($uomQuantity, 2));

        $product = Product::find($get('product_id'));

        $priceUnit = static::calculateUnitPrice($get('uom_id'), $product->cost ?? $product->price);

        $set('price_unit', round($priceUnit, 2));

        self::calculateLineTotals($set, $get);
    }

    private static function calculateUnitQuantity($uomId, $quantity)
    {
        if (! $uomId) {
            return $quantity;
        }

        $uom = Uom::find($uomId);

        return (float) ($quantity ?? 0) / $uom->factor;
    }

    private static function calculateUnitPrice($uomId, $price)
    {
        if (! $uomId) {
            return $price;
        }

        $uom = Uom::find($uomId);

        return (float) ($price / $uom->factor);
    }

    private static function calculateLineTotals(Forms\Set $set, Forms\Get $get): void
    {
        $lineData = [
            'product_id'     => $get('product_id'),
            'price_unit'     => $get('price_unit'),
            'quantity'       => $get('quantity'),
            'taxes'          => $get('taxes'),
            'discount'       => $get('discount'),
            'price_subtotal' => $get('price_subtotal'),
            'price_tax'      => $get('price_tax'),
            'price_total'    => $get('price_total'),
        ];

        $calculationService = app(MoveLineCalculationService::class);

        $updatedLineData = $calculationService->calculateLineTotals($lineData);

        $set('price_subtotal', $updatedLineData['price_subtotal']);
        $set('price_tax', $updatedLineData['price_tax']);
        $set('price_total', $updatedLineData['price_total']);
    }

    public static function collectTotals(AccountMove $record): void
    {
        $record->amount_untaxed = 0;
        $record->amount_tax = 0;
        $record->amount_total = 0;
        $record->amount_residual = 0;
        $record->amount_untaxed_signed = 0;
        $record->amount_untaxed_in_currency_signed = 0;
        $record->amount_tax_signed = 0;
        $record->amount_total_signed = 0;
        $record->amount_total_in_currency_signed = 0;
        $record->amount_residual_signed = 0;

        $lines = $record->lines->where('display_type', 'product');

        foreach ($lines as $line) {
            $lineData = [
                'product_id'     => $line->product_id,
                'price_unit'     => $line->price_unit,
                'quantity'       => $line->quantity,
                'taxes'          => $line->taxes->pluck('id')->toArray(),
                'discount'       => $line->discount,
                'price_subtotal' => $line->price_subtotal,
                'price_tax'      => $line->price_tax,
                'price_total'    => $line->price_total,
            ];

            $updatedLine = app(MoveLineCalculationService::class)->calculateLineTotals($lineData);

            $record->amount_untaxed += floatval($updatedLine['price_subtotal']);
            $record->amount_tax += floatval($updatedLine['price_tax']);
            $record->amount_total += floatval($updatedLine['price_total']);

            $record->amount_untaxed_signed += -floatval($updatedLine['price_subtotal']);
            $record->amount_untaxed_in_currency_signed += -floatval($updatedLine['price_subtotal']);
            $record->amount_tax_signed += -floatval($updatedLine['price_tax']);
            $record->amount_total_signed += -floatval($updatedLine['price_total']);
            $record->amount_total_in_currency_signed += -floatval($updatedLine['price_total']);

            $record->amount_residual += floatval($updatedLine['price_total']);
            $record->amount_residual_signed += -floatval($updatedLine['price_total']);
        }

        $record->save();

        static::updateOrCreatePaymentTermLine($record);

        static::updateOrCreateTaxLine($record);
    }

    public static function updateOrCreatePaymentTermLine($record): void
    {
        $dateMaturity = $record->invoice_date_due;

        if ($record->invoicePaymentTerm && $record->invoicePaymentTerm->dueTerm?->nb_days) {
            $dateMaturity = $dateMaturity->addDays($record->invoicePaymentTerm->dueTerm->nb_days);
        }

        $data = [
            'currency_id'              => $record->currency_id,
            'partner_id'               => $record->partner_id,
            'date_maturity'            => $dateMaturity,
            'company_id'               => $record->company_id,
            'company_currency_id'      => $record->company_currency_id,
            'commercial_partner_id'    => $record->partner_id,
            'parent_state'             => $record->state,
            'debit'                    => 0.00,
            'credit'                   => $record->amount_total,
            'balance'                  => -$record->amount_total,
            'amount_currency'          => -$record->amount_total,
            'amount_residual'          => -$record->amount_total,
            'amount_residual_currency' => -$record->amount_total,
        ];

        MoveLine::updateOrCreate(
            ['move_id' => $record->id, 'display_type' => 'payment_term'],
            array_merge($data, [
                'move_name' => $record->name,
                'sort'      => MoveLine::whereNotNull('sort')->max('sort') + 1,
                'date'      => now(),
                'creator_id' => $record->creator_id,
            ])
        );
    }

    private static function updateOrCreateTaxLine($record): void
    {
        $calculationService = app(MoveLineCalculationService::class);
        $lines = $record->lines->where('display_type', DisplayType::PRODUCT->value);
        $existingTaxLines = MoveLine::where('move_id', $record->id)->where('display_type', 'tax')->get()->keyBy('tax_line_id');
        $newTaxEntries = [];

        foreach ($lines as $line) {
            if ($line->taxes->isEmpty()) {
                continue;
            }

            $lineData = [
                'product_id' => $line->product_id,
                'price_unit' => $line->price_unit,
                'quantity'   => $line->quantity,
                'taxes'      => $line->taxes->pluck('id')->toArray(),
                'discount'   => $line->discount ?? 0,
            ];

            $calculatedLine = $calculationService->calculateLineTotals($lineData);
            $taxes = $line->taxes()->orderBy('sort')->get();
            $baseAmount = $calculatedLine['price_subtotal'];

            $taxCalculationResult = $calculationService->calculateTaxes(
                $lineData['taxes'],
                $baseAmount,
                $lineData['quantity'],
                $lineData['price_unit']
            );

            $taxesComputed = $taxCalculationResult['taxesComputed'];

            foreach ($taxes as $tax) {
                $computedTax = collect($taxesComputed)->firstWhere('tax_id', $tax->id);

                if (! $computedTax) {
                    continue;
                }

                $currentTaxAmount = $computedTax['tax_amount'];

                $currentTaxBase = $baseAmount;
                if ($tax->is_base_affected) {
                    foreach ($taxesComputed as $prevTax) {
                        if ($prevTax['include_base_amount'] && $prevTax['tax_id'] !== $tax->id) {
                            $currentTaxBase += $prevTax['tax_amount'];
                        }
                    }
                }

                if (isset($newTaxEntries[$tax->id])) {
                    $newTaxEntries[$tax->id]['credit'] += $currentTaxAmount;
                    $newTaxEntries[$tax->id]['balance'] -= $currentTaxAmount;
                    $newTaxEntries[$tax->id]['amount_currency'] -= $currentTaxAmount;
                } else {
                    $newTaxEntries[$tax->id] = [
                        'name'                  => $tax->name,
                        'move_id'               => $record->id,
                        'move_name'             => $record->name,
                        'display_type'          => 'tax',
                        'currency_id'           => $record->currency_id,
                        'partner_id'            => $record->partner_id,
                        'company_id'            => $record->company_id,
                        'company_currency_id'   => $record->company_currency_id,
                        'commercial_partner_id' => $record->partner_id,
                        'parent_state'          => $record->state,
                        'date'                  => now(),
                        'creator_id'            => $record->creator_id,
                        'debit'                 => $currentTaxAmount,
                        'credit'                => 0,
                        'balance'               => $currentTaxAmount,
                        'amount_currency'       => $currentTaxAmount,
                        'tax_base_amount'       => $currentTaxBase,
                        'tax_line_id'           => $tax->id,
                        'tax_group_id'          => $tax->tax_group_id,
                    ];
                }
            }
        }

        foreach ($newTaxEntries as $taxId => $taxData) {
            if (isset($existingTaxLines[$taxId])) {
                $existingTaxLines[$taxId]->update($taxData);
                unset($existingTaxLines[$taxId]);
            } else {
                $taxData['sort'] = MoveLine::max('sort') + 1;
                MoveLine::create($taxData);
            }
        }

        foreach ($existingTaxLines as $oldTaxLine) {
            $oldTaxLine->delete();
        }
    }
}
