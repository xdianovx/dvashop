<?php

namespace App\Enums;

enum HomepageQuickLinkCode: string
{
    case NewArrivals = 'new_arrivals';
    case Promotions = 'promotions';
    case ServiceSearch = 'service_search';
    case Reviews = 'reviews';
    case Socials = 'socials';
    case Galvanized = 'galvanized';
    case Fitting = 'fitting';
}
