<?php

namespace App\Console\Commands;

use App\Enums\ProductType;
use App\Models\Product;
use App\Services\Catalog\ProductOptionTemplateResolver;
use Illuminate\Console\Command;

class AssignDefaultProductOptionTemplate extends Command
{
    protected $signature = 'products:assign-default-option-template
        {--apply : Назначить шаблон; без флага выполняется только dry-run}';

    protected $description = 'Назначить стандартный шаблон автодеталям, у которых шаблон ещё не выбран';

    public function handle(ProductOptionTemplateResolver $resolver): int
    {
        $query = Product::query()
            ->where('product_type', ProductType::AutoPart->value)
            ->whereNull('product_option_template_id');
        $count = (clone $query)->count();

        $this->line('Автодеталей без шаблона: '.$count);

        $apply = (bool) $this->option('apply');
        $updated = 0;

        $query->select(['id', 'part_type_id'])->chunkById(200, function ($products) use ($resolver, $apply, &$updated): void {
            foreach ($products as $product) {
                $template = $resolver->resolveDefaultForAutoPart(
                    $product->part_type_id === null ? null : (int) $product->part_type_id,
                );

                if ($template === null) {
                    continue;
                }

                if (! $apply) {
                    $updated++;

                    continue;
                }

                $updated += Product::query()
                    ->whereKey($product->getKey())
                    ->whereNull('product_option_template_id')
                    ->update(['product_option_template_id' => $template->getKey()]);
            }
        });

        if (! $apply) {
            $this->line('Подходящий шаблон по умолчанию найден для товаров: '.$updated);
            $this->components->info('Dry-run завершён. База данных не изменялась.');

            return self::SUCCESS;
        }

        $this->components->info('Шаблон назначен товарам: '.$updated);
        $this->warn('Варианты автоматически не создавались.');

        return self::SUCCESS;
    }
}
