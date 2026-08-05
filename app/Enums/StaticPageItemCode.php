<?php

namespace App\Enums;

enum StaticPageItemCode: string
{
    case AboutMetricParts = 'about_metric_parts';
    case AboutMetricModels = 'about_metric_models';
    case AboutTechnologySteel = 'about_technology_steel';
    case AboutTechnologyScan = 'about_technology_scan';
    case AboutTechnologyCnc = 'about_technology_cnc';
    case HowStepChoose = 'how_step_choose';
    case HowStepConfirm = 'how_step_confirm';
    case HowStepPrepare = 'how_step_prepare';
    case HowStepHandover = 'how_step_handover';
    case HowStepReceive = 'how_step_receive';
    case HowStepPay = 'how_step_pay';
    case PartnersBenefitPrices = 'partners_benefit_prices';
    case PartnersBenefitManager = 'partners_benefit_manager';
    case PartnersBenefitRussia = 'partners_benefit_russia';
    case PartnersBenefitPriority = 'partners_benefit_priority';
    case PartnersTypeRetail = 'partners_type_retail';
    case PartnersTypeService = 'partners_type_service';
    case PartnersTypeOnline = 'partners_type_online';
    case PartnersTypeDropshipping = 'partners_type_dropshipping';
    case PartnersAboutProduction = 'partners_about_production';
    case PartnersAboutMeasurements = 'partners_about_measurements';
    case PartnersAboutPayment = 'partners_about_payment';
    case PartnersAboutMaterials = 'partners_about_materials';
    case PartnersAboutReturns = 'partners_about_returns';

    public function label(): string
    {
        return match ($this) {
            self::AboutMetricParts => 'Количество изготовленных деталей',
            self::AboutMetricModels => 'База моделей автомобилей',
            self::AboutTechnologySteel => 'Сталь',
            self::AboutTechnologyScan => '3D-сканирование',
            self::AboutTechnologyCnc => 'ЧПУ-оборудование',
            self::HowStepChoose => 'Выбор товара',
            self::HowStepConfirm => 'Подтверждение заказа',
            self::HowStepPrepare => 'Подготовка заказа',
            self::HowStepHandover => 'Передача в доставку',
            self::HowStepReceive => 'Получение заказа',
            self::HowStepPay => 'Оплата заказа',
            self::PartnersBenefitPrices => 'Специальные цены',
            self::PartnersBenefitManager => 'Персональный менеджер',
            self::PartnersBenefitRussia => 'Работа по всей России',
            self::PartnersBenefitPriority => 'Приоритетная отправка',
            self::PartnersTypeRetail => 'Оптовые и розничные сети',
            self::PartnersTypeService => 'СТО и кузовные сервисы',
            self::PartnersTypeOnline => 'Онлайн-продавцы',
            self::PartnersTypeDropshipping => 'Дропшиппинг',
            self::PartnersAboutProduction => 'Собственное производство',
            self::PartnersAboutMeasurements => 'База замеров',
            self::PartnersAboutPayment => 'Оплата при получении',
            self::PartnersAboutMaterials => 'Материалы',
            self::PartnersAboutReturns => 'Обмен и возврат',
        };
    }

    public function section(): StaticPageSectionCode
    {
        return match ($this) {
            self::AboutMetricParts,
            self::AboutMetricModels => StaticPageSectionCode::AboutMetrics,
            self::AboutTechnologySteel,
            self::AboutTechnologyScan,
            self::AboutTechnologyCnc => StaticPageSectionCode::AboutTechnologies,
            self::HowStepChoose,
            self::HowStepConfirm,
            self::HowStepPrepare,
            self::HowStepHandover,
            self::HowStepReceive,
            self::HowStepPay => StaticPageSectionCode::HowSteps,
            self::PartnersBenefitPrices,
            self::PartnersBenefitManager,
            self::PartnersBenefitRussia,
            self::PartnersBenefitPriority => StaticPageSectionCode::PartnersBenefits,
            self::PartnersTypeRetail,
            self::PartnersTypeService,
            self::PartnersTypeOnline,
            self::PartnersTypeDropshipping => StaticPageSectionCode::PartnersCooperation,
            self::PartnersAboutProduction,
            self::PartnersAboutMeasurements,
            self::PartnersAboutPayment,
            self::PartnersAboutMaterials,
            self::PartnersAboutReturns => StaticPageSectionCode::PartnersAbout,
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
