<?php

namespace Database\Seeders;

use App\Models\FaqCategory;
use App\Models\FaqItem;
use Database\Seeders\Concerns\FillsMissingSeederAttributes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FaqSeeder extends Seeder
{
    use FillsMissingSeederAttributes;

    public function run(): void
    {
        DB::transaction(function (): void {
            $categories = [];
            foreach ($this->categories() as $code => $attributes) {
                $category = FaqCategory::withTrashed()->firstOrNew(['code' => $code]);
                $categories[$code] = $this->fillMissing($category, $attributes);
                $categories[$code]->save();
            }

            foreach ($this->items() as $code => $attributes) {
                $categoryCode = $attributes['category'];
                unset($attributes['category']);
                $category = $categories[$categoryCode];
                $item = FaqItem::withTrashed()->firstOrNew(['code' => $code]);
                $this->fillMissing($item, ['faq_category_id' => $category->getKey(), ...$attributes])->save();
                if ((int) $item->faq_category_id !== (int) $category->getKey()) {
                    throw ValidationException::withMessages(['faq_category_id' => "Вопрос {$code} связан с неверной категорией."]);
                }
            }
        });
    }

    /** @return array<string, array<string, mixed>> */
    private function categories(): array
    {
        return [
            'common' => ['title' => 'Частые вопросы', 'is_active' => true, 'position' => 10],
            'products' => ['title' => 'Продукция', 'is_active' => true, 'position' => 20],
            'payment_delivery' => ['title' => 'Оплата и доставка', 'is_active' => true, 'position' => 30],
            'exchange_returns' => ['title' => 'Замена и возврат', 'is_active' => true, 'position' => 40],
            'website' => ['title' => 'Работа с сайтом', 'is_active' => true, 'position' => 50],
            'partners' => ['title' => 'Партнёры', 'is_active' => true, 'position' => 60],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function items(): array
    {
        return [
            'common_quality_exchange' => ['category' => 'common', 'question' => 'Товар НЕнадлежащего качества. Как поменять?', 'answer' => 'Напишите нам в течение 14 дней после получения. Мы согласуем замену или возврат и отправим инструкцию по отправке товара.', 'is_featured' => true, 'is_active' => true, 'position' => 10],
            'common_exchange_time' => ['category' => 'common', 'question' => 'Сколько времени ждать замены товара?', 'answer' => 'После согласования замены новая деталь отправляется в течение 3 рабочих дней. Срок доставки зависит от вашего региона и выбранной транспортной компании.', 'is_featured' => true, 'is_active' => true, 'position' => 20],
            'common_return_process' => ['category' => 'common', 'question' => 'Как происходит возврат?', 'answer' => 'Свяжитесь с нами удобным способом, опишите причину возврата и приложите фото. Мы подтвердим возврат и вышлем инструкцию по отправке товара обратно.', 'is_featured' => true, 'is_active' => true, 'position' => 30],
            'common_refund_process' => ['category' => 'common', 'question' => 'Как вернуть деньги?', 'answer' => 'После получения и проверки товара мы оформляем возврат средств. Деньги возвращаются тем же способом, которым была произведена оплата.', 'is_featured' => true, 'is_active' => true, 'position' => 40],
            'common_refund_destination' => ['category' => 'common', 'question' => 'Куда вернуться деньги?', 'answer' => 'Средства возвращаются на карту или счёт, с которого была произведена оплата заказа. Срок зачисления зависит от вашего банка — обычно от 3 до 10 рабочих дней.', 'is_featured' => true, 'is_active' => true, 'position' => 50],
            'common_defect_return' => ['category' => 'common', 'question' => 'Товар имеет заводской брак, повреждение или не соответствует заявленному. Как вернуть?', 'answer' => 'Сфотографируйте деталь и упаковку, напишите нам в течение 14 дней после получения. Мы проверим брак, согласуем возврат или замену и возьмём расходы на пересылку на себя.', 'is_featured' => false, 'is_active' => true, 'position' => 60],
            'products_availability' => ['category' => 'products', 'question' => 'У вас есть пороги на все модели авто?', 'answer' => 'Мы производим кузовные пороги и арки практически на все модели автомобилей. Если нужной позиции нет в каталоге — оставьте заявку, и мы изготовим деталь под ваш автомобиль.', 'is_featured' => false, 'is_active' => true, 'position' => 10],
            'products_material' => ['category' => 'products', 'question' => 'Из какого металла изготавливаются детали?', 'answer' => 'Детали изготавливаются из холоднокатаной стали той же толщины, что и заводские элементы кузова, и повторяют оригинальную геометрию.', 'is_featured' => false, 'is_active' => true, 'position' => 20],
            'products_preparation' => ['category' => 'products', 'question' => 'Нужна ли дополнительная обработка перед установкой?', 'answer' => 'Детали поставляются в транспортировочном грунте. Перед установкой рекомендуем нанести антикоррозийную обработку и покраску.', 'is_featured' => false, 'is_active' => true, 'position' => 30],
            'payment_methods' => ['category' => 'payment_delivery', 'question' => 'Какие способы оплаты доступны?', 'answer' => 'Оплата картой на сайте, по счёту для юридических лиц или при получении — способ выбирается при оформлении заказа.', 'is_featured' => false, 'is_active' => true, 'position' => 10],
            'delivery_process' => ['category' => 'payment_delivery', 'question' => 'Как осуществляется доставка?', 'answer' => 'Отправляем транспортными компаниями по всей России. Стоимость и сроки рассчитываются при оформлении заказа по вашему адресу.', 'is_featured' => false, 'is_active' => true, 'position' => 20],
            'delivery_cost' => ['category' => 'payment_delivery', 'question' => 'Сколько стоит доставка?', 'answer' => 'Стоимость зависит от габаритов деталей и региона доставки. Точный расчёт появится на этапе оформления заказа.', 'is_featured' => false, 'is_active' => true, 'position' => 30],
            'returns_period' => ['category' => 'exchange_returns', 'question' => 'В какой срок можно вернуть товар?', 'answer' => 'Вернуть товар надлежащего качества можно в течение 14 дней с момента получения, если деталь не устанавливалась и сохранён товарный вид.', 'is_featured' => false, 'is_active' => true, 'position' => 10],
            'returns_shipping_cost' => ['category' => 'exchange_returns', 'question' => 'Кто оплачивает пересылку при возврате?', 'answer' => 'При заводском браке или ошибке с нашей стороны пересылку оплачиваем мы. При возврате без причины — покупатель.', 'is_featured' => false, 'is_active' => true, 'position' => 20],
            'website_find_part' => ['category' => 'website', 'question' => 'Как найти деталь под мой автомобиль?', 'answer' => 'Воспользуйтесь быстрым поиском на главной: выберите марку и модель — покажем все подходящие детали.', 'is_featured' => false, 'is_active' => true, 'position' => 10],
            'website_registration' => ['category' => 'website', 'question' => 'Нужна ли регистрация для заказа?', 'answer' => 'Нет, заказ оформляется без регистрации. Достаточно указать контактные данные на странице оформления.', 'is_featured' => false, 'is_active' => true, 'position' => 20],
            'partners_wholesale' => ['category' => 'partners', 'question' => 'Как стать оптовым партнёром?', 'answer' => 'Напишите нам через раздел «Партнерам» или позвоните по бесплатному номеру — обсудим условия и предоставим прайс.', 'is_featured' => false, 'is_active' => true, 'position' => 10],
            'partners_discounts' => ['category' => 'partners', 'question' => 'Есть ли скидки для СТО и магазинов?', 'answer' => 'Да, для сервисов и магазинов действуют специальные условия в зависимости от объёма закупок.', 'is_featured' => false, 'is_active' => true, 'position' => 20],
        ];
    }
}
