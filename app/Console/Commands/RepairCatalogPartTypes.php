<?php

namespace App\Console\Commands;

use App\Services\Catalog\CatalogPartTypeRepairPlan;
use App\Services\Catalog\CatalogPartTypeRepairResult;
use App\Services\Catalog\CatalogPartTypeRepairService;
use Illuminate\Console\Command;
use Throwable;

class RepairCatalogPartTypes extends Command
{
    protected $signature = 'catalog:repair-part-types
        {--apply : Применить изменения в одной транзакции}
        {--show-suspects : Всегда показывать подробную таблицу подозрительных категорий}';

    protected $description = 'Диагностика и безопасный перенос legacy-категорий в архитектуру PartType';

    public function handle(CatalogPartTypeRepairService $service): int
    {
        $this->newLine();
        $this->components->info('Диагностика переноса типов деталей');
        $this->line('Режим: '.($this->option('apply') ? 'APPLY' : 'DRY-RUN'));

        if (! $this->option('apply')) {
            $this->warn('Изменения в базе не выполняются.');
            $this->line('Для применения используйте --apply.');
        }

        try {
            $plan = $service->inspect();
            $this->renderPlan($plan);

            if ($plan->hasBlockers()) {
                $this->error('Repair остановлен: устраните блокирующие конфликты и повторите диагностику.');

                return self::FAILURE;
            }

            if (! $this->option('apply')) {
                $this->components->info('Dry-run завершён. База данных не изменялась.');

                return self::SUCCESS;
            }

            $this->newLine();
            $this->components->info('Применение repair');
            $result = $service->apply($plan);
            $this->renderResult($result, $plan);
            $this->components->info('Repair успешно применён.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Ошибка применения repair: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->newLine();
            $this->warn('Импорт в этом этапе не изменён.');
            $this->warn('До следующего этапа интеграции импорта новый Excel-импорт запускать нельзя:');
            $this->warn('старая реализация импорта снова создаст технические ProductCategory.');
        }
    }

    private function renderPlan(CatalogPartTypeRepairPlan $plan): void
    {
        $this->newLine();
        $this->table(
            ['Показатель', 'Количество'],
            [
                ['Старые магазинные категории обнаружены', count($plan->legacyStoreCategories)],
                ['Старые магазинные категории перемещены', $plan->preview('legacy_store_categories_moved')],
                ['Старые магазинные категории объединены', $plan->preview('legacy_store_categories_merged')],
                ['Технические категории распознаны', count($plan->technicalCategories)],
                ['Неизвестные дочерние типы', count($plan->unknownChildren)],
                ['Подозрительные категории', count($plan->suspects)],
                ['PartType созданы', $plan->preview('part_types_created')],
                ['PartType восстановлены', $plan->preview('part_types_restored')],
                ['PartType уже существовали', $plan->preview('part_types_existing')],
                ['Импортные товары обновлены', $plan->preview('imported_products_updated')],
                ['Ручные товары обновлены', $plan->preview('manual_products_updated')],
                ['Товары уже были корректны', $plan->preview('products_already_correct')],
                ['Технические категории деактивированы', $plan->preview('technical_categories_deactivated')],
                ['Технические категории оставлены активными', $plan->preview('technical_categories_kept_active')],
                ['Fallback category использован', $plan->preview('fallback_used')],
                ['Warnings', count($plan->warnings)],
                ['Blockers', count($plan->blockers)],
            ],
        );

        if ($plan->legacyStoreCategories !== []) {
            $this->newLine();
            $this->line('<fg=yellow>Старые магазинные категории</>');
            $this->table(
                ['ID', 'Старый путь', 'Канонический путь', 'Товаров', 'Действие'],
                array_map(static fn (array $entry): array => [
                    $entry['category_id'],
                    $entry['legacy_path'],
                    $entry['canonical_path'],
                    $entry['products_count'],
                    match ($entry['action']) {
                        'merge' => 'Объединить',
                        'already_merged' => 'Уже объединена',
                        default => 'Переместить',
                    },
                ], $plan->legacyStoreCategories),
            );
        }

        if ($plan->technicalCategories !== []) {
            $this->newLine();
            $this->line('<fg=yellow>Распознанные технические категории</>');
            $this->table(
                ['ID', 'Старая категория', 'Товаров', 'PartType', 'Новая категория магазина', 'Действие'],
                array_map(static fn (array $entry): array => [
                    $entry['category_id'],
                    $entry['category_path'],
                    $entry['products_count'],
                    $entry['part_type_path'],
                    $entry['store_category_path'],
                    match ($entry['action']) {
                        'deactivate' => 'Деактивировать',
                        'already_deactivated' => 'Уже деактивирована',
                        'keep_active' => 'Оставить активной',
                        'migrate_products_keep_active' => 'Перенести товары, оставить активной',
                        default => 'Перенести товары и деактивировать',
                    },
                ], $plan->technicalCategories),
            );
        }

        if ($plan->suspects !== [] && ($this->option('show-suspects') || count($plan->suspects) <= 25)) {
            $this->newLine();
            $this->line('<fg=yellow>Подозрительные категории — требуется ручная проверка</>');
            $this->table(
                ['ID', 'Полный путь', 'Товаров', 'Причина'],
                array_map(static fn (array $entry): array => [
                    $entry['category_id'],
                    $entry['category_path'],
                    $entry['products_count'],
                    $entry['reason'],
                ], $plan->suspects),
            );
        } elseif ($plan->suspects !== []) {
            $this->warn('Подробности suspects скрыты. Используйте --show-suspects.');
        }

        foreach ($plan->warnings as $warning) {
            $this->warn('[WARNING] '.$warning->message);
        }

        foreach ($plan->blockers as $blocker) {
            $this->error('[BLOCKER] '.$blocker->message);
        }
    }

    private function renderResult(CatalogPartTypeRepairResult $result, CatalogPartTypeRepairPlan $plan): void
    {
        $this->newLine();
        $this->table(
            ['Результат', 'Количество'],
            [
                ['Старые магазинные категории перемещены', $result->counter('legacy_store_categories_moved')],
                ['Старые магазинные категории объединены', $result->counter('legacy_store_categories_merged')],
                ['PartType созданы', $result->counter('part_types_created')],
                ['PartType восстановлены', $result->counter('part_types_restored')],
                ['PartType уже существовали', $result->counter('part_types_existing')],
                ['Импортные товары обновлены', $result->counter('imported_products_updated')],
                ['Ручные товары обновлены', $result->counter('manual_products_updated')],
                ['Товары уже были корректны', $result->counter('products_already_correct')],
                ['Технические категории деактивированы', $result->counter('technical_categories_deactivated')],
                ['Технические категории оставлены активными', $result->counter('technical_categories_kept_active')],
                ['Fallback category использован', $result->counter('fallback_used')],
                ['Warnings', count($result->warnings)],
                ['Blockers', 0],
            ],
        );

        $plannedWarnings = [];

        foreach ($plan->warnings as $warning) {
            $plannedWarnings[$warning->code.'|'.$warning->message] = true;
        }

        foreach ($result->warnings as $warning) {
            if (isset($plannedWarnings[$warning->code.'|'.$warning->message])) {
                continue;
            }

            $this->warn('[WARNING] '.$warning->message);
        }
    }
}
