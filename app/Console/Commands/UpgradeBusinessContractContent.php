<?php

namespace App\Console\Commands;

use App\Services\SiteContent\SystemContentUpgradeService;
use Illuminate\Console\Command;

class UpgradeBusinessContractContent extends Command
{
    protected $signature = 'content:upgrade-business-contracts
        {--apply : Применить точные замены; без флага выполняется только dry-run}';

    protected $description = 'Обновить только неизменённые старые системные тексты под фактические условия магазина';

    public function handle(SystemContentUpgradeService $upgrades): int
    {
        $changes = $upgrades->pending();

        if ($changes === []) {
            $this->components->info('Точных старых системных значений для замены не найдено.');

            return self::SUCCESS;
        }

        foreach ($changes as $change) {
            $this->newLine();
            $this->line('code: '.$change['code']);
            $this->line('old: '.$change['old']);
            $this->line('new: '.$change['new']);
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->components->info('Dry-run завершён. База данных не изменялась. Для применения используйте --apply.');

            return self::SUCCESS;
        }

        $updated = $upgrades->apply($changes);
        $this->newLine();
        $this->components->info('Точно совпавших системных значений обновлено: '.$updated);

        return self::SUCCESS;
    }
}
