<?php

namespace App\Enums;

enum CartStatus: string
{
    case Active = 'active';
    case Ordered = 'ordered';
    case Abandoned = 'abandoned';
}
