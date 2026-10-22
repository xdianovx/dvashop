<?php

namespace App\Console\Commands;

use App\Models\ImportRun;
use App\Services\Import\ImportImageRecovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RecoverImportImages extends Command
{
    protected $signature = 'catalog-import:recover-images {run} {--apply : Reconcile counters and restore proven lost image jobs} {--confirm= : Exact confirmation from read-only inspection}';

    protected $description = 'Inspect/recover image results only; never restarts a FULL or writes catalogue data.';

    public function handle(ImportImageRecovery $recovery): int
    {
        if (! app()->environment('local', 'testing') || (app()->environment('local')
            && (! in_array(DB::connection()->getConfig('host'), ['mysql', '127.0.0.1', 'localhost'], true) || DB::connection()->getDatabaseName() !== 'dvashop'))) {
            throw new RuntimeException('Команда разрешена только для проверенной локальной БД DVASHOP.');
        }
        $run = ImportRun::findOrFail((int) $this->argument('run'));
        if ($this->option('apply')) {
            if (! $this->option('confirm')) {
                $this->error('Требуется --confirm из предварительной сверки.');

                return self::FAILURE;
            }
            $plan = $recovery->recover($run, $this->option('confirm'));
        } else {
            if (DB::getDriverName() === 'mysql' && DB::transactionLevel() === 0) {
                DB::statement('SET TRANSACTION READ ONLY');
            }
            DB::beginTransaction();
            try {
                $plan = $recovery->inspect($run);
            } finally {
                DB::rollBack();
            }
        }
        $this->line(json_encode(array_intersect_key($plan, array_flip(['run_id', 'status', 'before', 'after', 'counts', 'conflicts', 'confirmation']))
            + ['applied' => (bool) $this->option('apply')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $plan['conflicts'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
