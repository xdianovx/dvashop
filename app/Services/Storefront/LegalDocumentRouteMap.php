<?php

namespace App\Services\Storefront;

use App\Enums\LegalDocumentCode;

final class LegalDocumentRouteMap
{
    public function routeName(LegalDocumentCode $code): string
    {
        return match ($code) {
            LegalDocumentCode::PrivacyPolicy => 'legal.privacy-policy',
            LegalDocumentCode::SaleRules => 'legal.sale-rules',
            LegalDocumentCode::ReturnsExchange => 'legal.returns-exchange',
            LegalDocumentCode::InformationUsageRules => 'legal.information-usage-rules',
        };
    }

    public function url(LegalDocumentCode $code): string
    {
        return route($this->routeName($code));
    }
}
