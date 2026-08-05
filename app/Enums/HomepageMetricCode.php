<?php

namespace App\Enums;

enum HomepageMetricCode: string
{
    case SinceYear = 'since_year';
    case VehicleDatabase = 'vehicle_database';
    case ItemsSold = 'items_sold';
    case OriginalFit = 'original_fit';
    case PriceAdvantage = 'price_advantage';
}
