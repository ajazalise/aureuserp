<?php

namespace Webkul\Invoice\Filament\Clusters\Configuration\Resources;

use Webkul\Account\Filament\Clusters\Configuration\Resources\TaxGroupResource as BaseTaxGroupResource;
use Webkul\Invoice\Filament\Clusters\Configuration\Resources\TaxGroupResource\Pages;
use Webkul\Invoice\Filament\Clusters\Configuration;

class TaxGroupResource extends BaseTaxGroupResource
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $cluster = Configuration::class;

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTaxGroups::route('/'),
            'create' => Pages\CreateTaxGroup::route('/create'),
            'view'   => Pages\ViewTaxGroup::route('/{record}'),
            'edit'   => Pages\EditTaxGroup::route('/{record}/edit'),
        ];
    }
}
