<?php

namespace App\Enums;

enum LegalDocumentCode: string
{
    case PrivacyPolicy = 'privacy_policy';
    case SaleRules = 'sale_rules';
    case ReturnsExchange = 'returns_exchange';
    case InformationUsageRules = 'information_usage_rules';

    public function label(): string
    {
        return match ($this) {
            self::PrivacyPolicy => 'Политика конфиденциальности',
            self::SaleRules => 'Правила продажи',
            self::ReturnsExchange => 'Возврат и обмен',
            self::InformationUsageRules => 'Правила использования информации',
        };
    }
}
