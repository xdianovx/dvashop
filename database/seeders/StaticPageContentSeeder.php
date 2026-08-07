<?php

namespace Database\Seeders;

use App\Enums\StaticPageCode;
use App\Enums\StaticPageItemCode;
use App\Enums\StaticPageSectionCode;
use App\Models\StaticPage;
use App\Models\StaticPageItem;
use App\Models\StaticPageSection;
use Database\Seeders\Concerns\FillsMissingSeederAttributes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaticPageContentSeeder extends Seeder
{
    use FillsMissingSeederAttributes;

    public function run(): void
    {
        DB::transaction(function (): void {
            $pages = [];
            foreach ($this->pages() as $code => $attributes) {
                $page = StaticPage::query()->firstOrNew(['code' => $code]);
                $pages[$code] = $this->fillMissing($page, $attributes);
                $pages[$code]->save();
            }

            $sections = [];
            foreach ($this->sections() as $code => $attributes) {
                $sectionCode = StaticPageSectionCode::from($code);
                $page = $pages[$sectionCode->page()->value];
                $section = StaticPageSection::query()->firstOrNew(['code' => $code]);
                $this->fillMissing($section, ['static_page_id' => $page->getKey(), ...$attributes])->save();
                if ((int) $section->static_page_id !== (int) $page->getKey()) {
                    throw ValidationException::withMessages(['static_page_id' => "Блок {$code} связан с неверной страницей."]);
                }
                $sections[$code] = $section;
            }

            foreach ($this->items() as $code => $attributes) {
                $itemCode = StaticPageItemCode::from($code);
                $section = $sections[$itemCode->section()->value];
                $item = StaticPageItem::query()->firstOrNew(['code' => $code]);
                $this->fillMissing($item, ['static_page_section_id' => $section->getKey(), ...$attributes])->save();
                if ((int) $item->static_page_section_id !== (int) $section->getKey()) {
                    throw ValidationException::withMessages(['static_page_section_id' => "Элемент {$code} связан с неверным блоком."]);
                }
            }
        });
    }

    /** @return array<string, array<string, mixed>> */
    private function pages(): array
    {
        return [
            StaticPageCode::About->value => [
                'title' => 'О нас',
                'subtitle' => null,
                'primary_action_label' => null,
                'secondary_action_label' => null,
                'is_active' => true,
                'position' => 10,
            ],
            StaticPageCode::How->value => [
                'title' => 'Как мы работаем',
                'subtitle' => null,
                'primary_action_label' => null,
                'secondary_action_label' => null,
                'is_active' => true,
                'position' => 20,
            ],
            StaticPageCode::Payment->value => [
                'title' => 'Оплата и доставка',
                'subtitle' => null,
                'primary_action_label' => null,
                'secondary_action_label' => null,
                'is_active' => true,
                'position' => 30,
            ],
            StaticPageCode::Faq->value => [
                'title' => 'Вопросы и ответы',
                'subtitle' => 'Здесь вы найдете ответы на частые вопросы по нашему сервису',
                'primary_action_label' => null,
                'secondary_action_label' => null,
                'is_active' => true,
                'position' => 40,
            ],
            StaticPageCode::Partners->value => [
                'title' => 'Преимущества работы с AVTOPOROGI.RU',
                'subtitle' => 'Для постоянных клиентов действуют специальные условия на покупку и доставку кузовных запчастей',
                'primary_action_label' => null,
                'secondary_action_label' => null,
                'is_active' => true,
                'position' => 50,
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function sections(): array
    {
        return [
            StaticPageSectionCode::AboutHero->value => [
                'label' => 'О компании',
                'title' => 'Наша экспертиза — ваше преимущество!',
                'subtitle' => null,
                'body' => 'С 2014 года мы специализируемся на производстве высококачественных автомобильных кузовных деталей: ремонтных порогов, арок, ремкомплектов дверей, багажника и пола',
                'is_active' => true,
                'position' => 10,
            ],
            StaticPageSectionCode::AboutMetrics->value => [
                'label' => null,
                'title' => null,
                'subtitle' => null,
                'body' => null,
                'is_active' => true,
                'position' => 20,
            ],
            StaticPageSectionCode::AboutTechnologies->value => [
                'label' => null,
                'title' => 'Технологии точности',
                'subtitle' => 'Для безупречного соответствия оригиналу мы используем комплексный подход:',
                'body' => null,
                'is_active' => true,
                'position' => 30,
            ],
            StaticPageSectionCode::AboutGoal->value => [
                'label' => 'Наша цель',
                'title' => null,
                'subtitle' => null,
                'body' => 'предлагать надежные и точные решения, которые экономят ваше время и деньги, сохраняя высокое качество ремонта.',
                'is_active' => true,
                'position' => 40,
            ],
            StaticPageSectionCode::HowSteps->value => [
                'label' => null,
                'title' => null,
                'subtitle' => null,
                'body' => null,
                'is_active' => true,
                'position' => 10,
            ],
            StaticPageSectionCode::PartnersBenefits->value => [
                'label' => null,
                'title' => null,
                'subtitle' => null,
                'body' => null,
                'is_active' => true,
                'position' => 10,
            ],
            StaticPageSectionCode::PartnersCooperation->value => [
                'label' => null,
                'title' => 'Приглашаем к сотрудничеству',
                'subtitle' => null,
                'body' => null,
                'is_active' => true,
                'position' => 20,
            ],
            StaticPageSectionCode::PartnersAbout->value => [
                'label' => null,
                'title' => 'Автопороги.ру - это',
                'subtitle' => null,
                'body' => null,
                'is_active' => true,
                'position' => 30,
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function items(): array
    {
        return [
            StaticPageItemCode::AboutMetricParts->value => [
                'label' => null,
                'title' => '150 000+ деталей',
                'text' => 'За годы работы мы изготовили более 150 000 деталей',
                'is_active' => true,
                'position' => 10,
            ],
            StaticPageItemCode::AboutMetricModels->value => [
                'label' => null,
                'title' => '3000 моделей автомобилей',
                'text' => 'Создали одну из самых полных в России баз геометрии кузовных элементов.',
                'is_active' => true,
                'position' => 20,
            ],
            StaticPageItemCode::AboutTechnologySteel->value => [
                'label' => null,
                'title' => null,
                'text' => 'Качественная высокоуглеродистая сталь толщиной 0,8 - 1,5 мм, обеспечивающая прочность и долговечность.',
                'is_active' => true,
                'position' => 10,
            ],
            StaticPageItemCode::AboutTechnologyScan->value => [
                'label' => null,
                'title' => null,
                'text' => '3D-сканирование для точного повторения сложных геометрий.',
                'is_active' => true,
                'position' => 20,
            ],
            StaticPageItemCode::AboutTechnologyCnc->value => [
                'label' => null,
                'title' => null,
                'text' => 'Современное ЧПУ-оборудование для идеального раскроя и гибки.',
                'is_active' => true,
                'position' => 30,
            ],
            StaticPageItemCode::HowStepChoose->value => [
                'label' => null,
                'title' => 'Выбираете товар и оставляете заявку',
                'text' => 'Оставьте заявку, самостоятельно подобрав товар в каталоге и оформив заказ через корзину, либо позвоните по бесплатному номеру:',
                'is_active' => true,
                'position' => 10,
            ],
            StaticPageItemCode::HowStepConfirm->value => [
                'label' => null,
                'title' => 'Перезваниваем и уточняем детали',
                'text' => 'Компетентные менеджеры с опытом работы более 3 лет перезвонят, уточнят детали и ответят на все интересующие вас вопросы, чтобы сэкономить ваше время и деньги',
                'is_active' => true,
                'position' => 20,
            ],
            StaticPageItemCode::HowStepPrepare->value => [
                'label' => null,
                'title' => 'Оформляем и готовим заказ к отправке',
                'text' => 'Каждому заказу присваивается внутренний номер, после чего он упаковывается нашими сотрудниками на складе в Санкт-Петербурге. Детали уточняйте при оформлении',
                'is_active' => true,
                'position' => 30,
            ],
            StaticPageItemCode::HowStepHandover->value => [
                'label' => null,
                'title' => 'Передаем груз в службу доставки',
                'text' => 'Avtoporogi сотрудничает с крупнейшей ТК России — СДЭК. Это позволяет предложить нам лучшие условия доставки, даже если вы живете в небольшом городке',
                'is_active' => true,
                'position' => 40,
            ],
            StaticPageItemCode::HowStepReceive->value => [
                'label' => null,
                'title' => 'Курьер доставляет Ваш заказ',
                'text' => 'Вы можете получить свой заказ в ближайшем пункте выдачи ТК или прямо из рук курьера по месту жительства',
                'is_active' => true,
                'position' => 50,
            ],
            StaticPageItemCode::HowStepPay->value => [
                'label' => null,
                'title' => 'Оплачиваете покупку при получении',
                'text' => 'Оплата заказа возможна наличными, картой и по счету (для юрлиц).',
                'is_active' => true,
                'position' => 60,
            ],
            StaticPageItemCode::PartnersBenefitPrices->value => ['label' => null, 'title' => 'Специальные цены на детали', 'text' => null, 'is_active' => true, 'position' => 10],
            StaticPageItemCode::PartnersBenefitManager->value => ['label' => null, 'title' => 'Персональный менеджер', 'text' => null, 'is_active' => true, 'position' => 20],
            StaticPageItemCode::PartnersBenefitRussia->value => ['label' => null, 'title' => 'Работает по всей РФ', 'text' => null, 'is_active' => true, 'position' => 30],
            StaticPageItemCode::PartnersBenefitPriority->value => ['label' => null, 'title' => 'Приоритет в отправке', 'text' => null, 'is_active' => true, 'position' => 40],
            StaticPageItemCode::PartnersTypeRetail->value => ['label' => null, 'title' => 'Оптовые и роздничные сети', 'text' => null, 'is_active' => true, 'position' => 10],
            StaticPageItemCode::PartnersTypeService->value => ['label' => null, 'title' => 'СТО и частные кузовные сервисы', 'text' => null, 'is_active' => true, 'position' => 20],
            StaticPageItemCode::PartnersTypeOnline->value => ['label' => null, 'title' => 'Онлайн продавец запчастей', 'text' => null, 'is_active' => true, 'position' => 30],
            StaticPageItemCode::PartnersTypeDropshipping->value => ['label' => null, 'title' => 'Дропшиппинг', 'text' => null, 'is_active' => true, 'position' => 40],
            StaticPageItemCode::PartnersAboutProduction->value => ['label' => null, 'title' => null, 'text' => 'Собственное производство. Детали в наличии или изготовим за 1 день с момента обращения', 'is_active' => true, 'position' => 10],
            StaticPageItemCode::PartnersAboutMeasurements->value => ['label' => null, 'title' => null, 'text' => 'База замеров деталей на более 3000 автомобилей', 'is_active' => true, 'position' => 20],
            StaticPageItemCode::PartnersAboutPayment->value => ['label' => null, 'title' => null, 'text' => 'Оплата при получении. Проверяете, потом оплачиваете', 'is_active' => true, 'position' => 30],
            StaticPageItemCode::PartnersAboutMaterials->value => ['label' => null, 'title' => null, 'text' => 'Используем металл ХКС и цинк от 0,8 до 1.5 мм', 'is_active' => true, 'position' => 40],
            StaticPageItemCode::PartnersAboutReturns->value => ['label' => null, 'title' => null, 'text' => 'Удобный обмен и лёгкий возврат по заказам', 'is_active' => true, 'position' => 50],
        ];
    }
}
