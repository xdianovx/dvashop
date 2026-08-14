<?php

namespace App\Services\SiteContent;

use App\Enums\StaticPageItemCode;
use App\Models\FaqItem;
use App\Models\StaticPageItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SystemContentUpgradeService
{
    /**
     * @return list<array{code:string,model:class-string<Model>,record_code:string,field:string,old:string,new:string}>
     */
    public function pending(): array
    {
        return collect($this->definitions())
            ->filter(function (array $definition): bool {
                $record = $this->query($definition['model'])
                    ->where('code', $definition['record_code'])
                    ->first();

                return $record instanceof Model
                    && $record->getAttribute($definition['field']) === $definition['old'];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array{code:string,model:class-string<Model>,record_code:string,field:string,old:string,new:string}>  $changes
     */
    public function apply(array $changes): int
    {
        return DB::transaction(function () use ($changes): int {
            $updated = 0;

            foreach ($changes as $change) {
                $record = $this->query($change['model'])
                    ->where('code', $change['record_code'])
                    ->lockForUpdate()
                    ->first();

                if (! $record instanceof Model || $record->getAttribute($change['field']) !== $change['old']) {
                    continue;
                }

                $record->forceFill([$change['field'] => $change['new']])->save();
                $updated++;
            }

            return $updated;
        });
    }

    /** @param class-string<Model> $model */
    private function query(string $model): Builder
    {
        return $model === FaqItem::class
            ? FaqItem::withTrashed()
            : $model::query();
    }

    /**
     * @return list<array{code:string,model:class-string<Model>,record_code:string,field:string,old:string,new:string}>
     */
    private function definitions(): array
    {
        return [
            [
                'code' => 'faq.payment_methods.answer',
                'model' => FaqItem::class,
                'record_code' => 'payment_methods',
                'field' => 'answer',
                'old' => 'Оплата картой на сайте, по счёту для юридических лиц или при получении — способ выбирается при оформлении заказа.',
                'new' => 'После подтверждения заказа можно оплатить картой или через СБП по ссылке либо QR-коду, по счёту для юридических лиц, а при получении — только если этот способ активен при оформлении.',
            ],
            [
                'code' => 'faq.delivery_process.answer',
                'model' => FaqItem::class,
                'record_code' => 'delivery_process',
                'field' => 'answer',
                'old' => 'Отправляем транспортными компаниями по всей России. Стоимость и сроки рассчитываются при оформлении заказа по вашему адресу.',
                'new' => 'Отправляем транспортными компаниями по всей России. После оформления менеджер подтвердит способ, стоимость и срок доставки.',
            ],
            [
                'code' => 'faq.delivery_cost.answer',
                'model' => FaqItem::class,
                'record_code' => 'delivery_cost',
                'field' => 'answer',
                'old' => 'Стоимость зависит от габаритов деталей и региона доставки. Точный расчёт появится на этапе оформления заказа.',
                'new' => 'Самовывоз бесплатный. Фиксированная стоимость указывается при оформлении, а стоимость доставки по запросу уточнит менеджер после подтверждения заказа.',
            ],
            [
                'code' => 'static.how_step_pay.title',
                'model' => StaticPageItem::class,
                'record_code' => StaticPageItemCode::HowStepPay->value,
                'field' => 'title',
                'old' => 'Оплачиваете покупку при получении',
                'new' => 'Оплачиваете заказ доступным способом',
            ],
            [
                'code' => 'static.how_step_pay.text',
                'model' => StaticPageItem::class,
                'record_code' => StaticPageItemCode::HowStepPay->value,
                'field' => 'text',
                'old' => 'Оплата заказа возможна наличными, картой и по счету (для юрлиц).',
                'new' => 'После подтверждения заказа менеджером оплатите его выбранным доступным способом.',
            ],
            [
                'code' => 'static.partners_about_payment.text',
                'model' => StaticPageItem::class,
                'record_code' => StaticPageItemCode::PartnersAboutPayment->value,
                'field' => 'text',
                'old' => 'Оплата при получении. Проверяете, потом оплачиваете',
                'new' => 'Условия оплаты согласуем при подтверждении заказа: доступны СБП, карта по ссылке, счёт для юридических лиц и активные способы при получении.',
            ],
        ];
    }
}
