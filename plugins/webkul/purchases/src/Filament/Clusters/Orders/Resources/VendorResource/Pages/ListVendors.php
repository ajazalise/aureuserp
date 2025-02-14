<?php

namespace Webkul\Purchase\Filament\Clusters\Orders\Resources\VendorResource\Pages;

use Webkul\Purchase\Filament\Clusters\Orders\Resources\VendorResource;
use Webkul\Partner\Filament\Resources\PartnerResource\Pages\ListPartners as BaseListPartners;

class ListVendors extends BaseListPartners
{
    protected static string $resource = VendorResource::class;
}
