<?php

namespace App\Console\Commands;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductOptionTemplate;
use Illuminate\Console\Command;

class AssignDefaultProductOptionTemplate extends Command
{
    protected $signature = 'products:assign-default-option-template
        {--apply : Назначить шаблон; без флага выполняется только dry-run}';

    protected $description = 'Назначить стандартный шаблон автодеталям, у которых шаблон ещё не выбран';

    public function handle(): int
    {
        $template = ProductOptionTemplate::query()
            ->where('slug', 'default_auto_part')
            ->where('is_active', true)
            ->first();

        if (! $template instanceof ProductOptionTemplate) {
            $this->error('Активный шаблон default_auto_part не найден. Сначала запустите ProductOptionSeeder.');

            return self::FAILURE;
        }

        $query = Product::query()
            ->where('product_type', ProductType::AutoPart->value)
            ->whereNull('product_option_template_id');
        $count = (clone $query)->count();

        $this->line('Автодеталей без шаблона: '.$count);

        if (! $this->option('apply')) {
            $this->components->info('Dry-run завершён. База данных не изменялась.');

            return self::SUCCESS;
        }

        $updated = $query->update(['product_option_template_id' => $template->getKey()]);
        $this->components->info('Шаблон назначен товарам: '.$updated);
        $this->warn('Варианты автоматически не создавались.');

        return self::SUCCESS;
    }
}
