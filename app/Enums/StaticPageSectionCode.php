<?php

namespace App\Enums;

enum StaticPageSectionCode: string
{
    case AboutHero = 'about_hero';
    case AboutMetrics = 'about_metrics';
    case AboutTechnologies = 'about_technologies';
    case AboutGoal = 'about_goal';
    case HowSteps = 'how_steps';
    case PartnersBenefits = 'partners_benefits';
    case PartnersCooperation = 'partners_cooperation';
    case PartnersAbout = 'partners_about';

    public function label(): string
    {
        return match ($this) {
            self::AboutHero => 'Вводный блок «О нас»',
            self::AboutMetrics => 'Показатели компании',
            self::AboutTechnologies => 'Технологии точности',
            self::AboutGoal => 'Цель компании',
            self::HowSteps => 'Шаги работы',
            self::PartnersBenefits => 'Преимущества для партнёров',
            self::PartnersCooperation => 'Форматы сотрудничества',
            self::PartnersAbout => 'О компании для партнёров',
        };
    }

    public function page(): StaticPageCode
    {
        return match ($this) {
            self::AboutHero,
            self::AboutMetrics,
            self::AboutTechnologies,
            self::AboutGoal => StaticPageCode::About,
            self::HowSteps => StaticPageCode::How,
            self::PartnersBenefits,
            self::PartnersCooperation,
            self::PartnersAbout => StaticPageCode::Partners,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $code): array => [$code->value => $code->label()])
            ->all();
    }
}
