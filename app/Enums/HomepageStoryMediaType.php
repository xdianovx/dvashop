<?php

namespace App\Enums;

enum HomepageStoryMediaType: string
{
    case Image = 'image';
    case Video = 'video';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Изображение',
            self::Video => 'Видео',
        };
    }
}
