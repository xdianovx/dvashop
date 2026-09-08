<?php

namespace App\Enums;

enum LegalDocumentContentType: string
{
    case Page = 'page';
    case Pdf = 'pdf';

    public static function options(): array
    {
        return [self::Page->value => 'Страница', self::Pdf->value => 'PDF-файл'];
    }
}
