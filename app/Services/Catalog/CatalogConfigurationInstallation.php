<?php

namespace App\Services\Catalog;

use App\Enums\AdminPermission;
use App\Enums\ImportRunStatus;
use App\Models\CatalogConfiguration;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\Import\CatalogImportProductPlan;
use App\Services\Import\CatalogSafePartialImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Reviewed CSV is authoritative for every current managed price, regardless of historical mode. */
final class CatalogConfigurationInstallation
{
    public function preview(array $rows): array
    {
        $desired = [];
        foreach ($rows as $row) {
            $paths = CatalogConfigurationSource::targetPaths($row);
            foreach ($paths as $path) {
                $desired[$path.':'.$row['signature']] = $row;
            }
        }
        $current = app(CatalogConfigurations::class)->ready()
            ? CatalogConfiguration::with('partType', 'template')->whereHas('template',
                fn ($q) => $q->where('slug', 'like', 'matrix_%'))->orderBy('id')->get()
            : collect();
        // Include every previously installed matrix template, including types
        // that disappeared from this CSV. Only matrix templates are eligible for retirement.
        $report = ['added' => [], 'retired' => [], 'reactivated' => [], 'price_conflicts' => []];
        foreach ($current as $row) {
            $key = $row->partType->full_slug.':'.$row->signature;
            $source = $desired[$key] ?? null;
            if (! $source) {
                if ($row->is_active) {
                    $report['retired'][] = ['id' => $row->id, 'type' => $row->partType->full_slug, 'label' => $row->label()];
                }

                continue;
            }
            if (! $row->is_active) {
                $report['reactivated'][] = ['id' => $row->id, 'type' => $row->partType->full_slug, 'label' => $row->label()];
            }
            if ($row->price !== $source['price']) {
                $report['price_conflicts'][] = ['id' => $row->id, 'type' => $row->partType->full_slug, 'label' => $row->label(), 'previous_price' => $row->price, 'csv_price' => $source['price']];
            }
            unset($desired[$key]);
        }
        foreach ($desired as $key => $row) {
            $report['added'][] = ['key' => $key, 'options' => $row['options'], 'price' => $row['price']];
        }
        $report['requires_confirmation'] = $report['retired'] !== [] || $report['reactivated'] !== [] || $report['price_conflicts'] !== []
            || ($current->isNotEmpty() && $report['added'] !== []);
        $report['confirmation'] = hash('sha256', json_encode([$rows, $current->map(fn ($row) => $row->only(['id', 'part_type_id', 'product_option_template_id', 'signature', 'options', 'price', 'is_active', 'position', 'updated_at']))->values()->all()], JSON_THROW_ON_ERROR));

        return $report;
    }

    /** Read-only CSV review, bound to one run and the uploaded file's exact bytes. */
    public function previewImport(ImportRun $run): array
    {
        if ($run->import_mode === 'safe_partial') {
            $review = app(CatalogSafePartialImport::class)->preview($run);
            $review['matrix_confirmation'] = $review['confirmation'];
            $review['confirmation'] = $review['safe_confirmation'];

            return $review;
        }
        $path = $this->csvPath($run);
        $expected = $run->import_mode === 'full' ? $run->configuration_hash : $run->file_hash;
        $hash = is_file($path) ? hash_file('sha256', $path) : false;
        if (! is_string($hash) || ! is_string($expected) || ! hash_equals($expected, $hash)) {
            $this->reject('CSV изменился или недоступен. Загрузите исходный файл заново.');
        }
        $rows = app(CatalogConfigurationSource::class)->read($path);
        if (! hash_equals($hash, (string) hash_file('sha256', $path))) {
            $this->reject('CSV изменился во время чтения.');
        }

        return $this->bindReview($run, $this->preview($rows), $hash);
    }

    /** Only an explicit authorized administrator action may persist consent. */
    public function confirmImport(ImportRun $run, User $user, string $reviewToken): ImportRun
    {
        if ($run->import_mode === 'safe_partial') {
            return app(CatalogSafePartialImport::class)->confirm($run, $user, $reviewToken);
        }
        abort_unless($user->canPerformAdminAction(AdminPermission::ManageCatalogImports), 403);

        return DB::transaction(function () use ($run, $user, $reviewToken): ImportRun {
            app(CatalogPriceGuard::class)->assertWritable();
            $locked = ImportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ImportRunStatus::Ready || $locked->initialized_at !== null) {
                $this->reject('Подтвердить можно только ещё не запущенный импорт.');
            }
            $review = $this->previewImport($locked);
            if ($locked->import_mode === 'full' && (! ($review['execution_allowed'] ?? false) || ! ($review['schema_ready'] ?? false))) {
                $this->reject('Полный импорт заблокирован проверкой источников или схемы защиты.');
            }
            if (! hash_equals($review['confirmation'], $reviewToken)) {
                $this->reject('Предпросмотр устарел. Рассчитайте изменения CSV заново и подтвердите их.');
            }
            $locked->forceFill([
                'configuration_review' => $review,
                'configuration_confirmed_hash' => $review['csv_sha256'],
                'configuration_confirmed_by' => $user->id,
                'configuration_confirmed_at' => now(),
            ])->save();

            return $locked;
        });
    }

    /** Verification never creates or replaces consent, including for an initially empty matrix. */
    public function assertImportConfirmation(ImportRun $run, array $currentReview): string
    {
        if ($run->import_mode === 'safe_partial') {
            return app(CatalogSafePartialImport::class)->assertPreview($run, $currentReview);
        }
        $approved = $run->configuration_review;
        if (! $run->configuration_confirmed_at || ! $run->configuration_confirmed_by || ! is_array($approved)
            || ! is_string($run->configuration_confirmed_hash)) {
            $this->reject('Нет подтверждения администратора для CSV этого импорта.');
        }
        if ($run->import_mode === 'full' && (! ($currentReview['execution_allowed'] ?? false) || ! ($currentReview['schema_ready'] ?? false))) {
            $this->reject('Полный импорт заблокирован проверкой источников или схемы защиты.');
        }
        if (! is_string($approved['confirmation'] ?? null) || ! is_string($approved['matrix_confirmation'] ?? null)
            || ! hash_equals($run->configuration_confirmed_hash, $currentReview['csv_sha256'])
            || ! hash_equals($approved['confirmation'], $currentReview['confirmation'])) {
            $this->reject('Подтверждённый предпросмотр устарел или CSV изменился. Требуется новое подтверждение.');
        }

        return $approved['matrix_confirmation'];
    }

    public function csvPath(ImportRun $run): string
    {
        if (! in_array($run->import_mode, ['full', 'csv'], true)) {
            $this->reject('Для этого режима подтверждение CSV не применяется.');
        }
        $path = $run->import_mode === 'full' ? $run->configuration_path : $run->stored_path;
        if (! is_string($path) || $path === '') {
            $this->reject('Для импорта не загружен CSV конфигураций.');
        }

        return Storage::disk('local')->path($path);
    }

    public function bindReview(ImportRun $run, array $review, string $csvHash): array
    {
        $full = null;
        if ($run->import_mode === 'full') {
            $xlsx = Storage::disk('local')->path($run->stored_path);
            if (! is_file($xlsx) || ! hash_equals((string) $run->file_hash, (string) hash_file('sha256', $xlsx))) {
                $this->reject('Excel изменился после загрузки.');
            }
            $full = app(CatalogImportProductPlan::class)->full($xlsx, $run->type);
            $isolation = app(CatalogImportProtections::class)->installation(app(CatalogConfigurationSource::class)->read($this->csvPath($run)), array_keys($full['full_scope']['preserve']));
            $review['assortment_hash'] = $full['full_scope']['hash'];
            $review['assortment_counts'] = $full['execution_counts'];
            $review['structural_counts'] = $full['structural_counts'];
            $review['mode'] = 'full';
            $review['execution_allowed'] = $full['execution_allowed'] && $isolation['blocked_types'] === [];
            $review['installation'] = $isolation;
            $protectedIds = array_flip(app(CatalogImportProtections::class)->ids());
            $needsArchiveGuard = array_intersect_key($full['full_scope']['archives'], $protectedIds) !== [];
            $review['schema_ready'] = ! $needsArchiveGuard || app(CatalogImportDatabaseGuards::class)->fullArchiveReady();
            $review['excel_complete'] = $full['excel_complete'];
            $review['exception_counts'] = array_count_values(array_column($full['exceptions'], 'code'));
        }
        $review['matrix_confirmation'] = $review['confirmation'];
        $review['run_id'] = $run->id;
        $review['csv_sha256'] = $csvHash;
        $binding = [
            $run->id, $run->import_mode, $run->type, $run->stored_path, $run->file_hash,
            $run->configuration_path, $run->configuration_hash, $csvHash, $review['matrix_confirmation'],
        ];
        if ($full !== null) {
            $binding[] = $full['full_scope']['hash'];
        }
        $review['confirmation'] = hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR));

        return $review;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['configurationReview' => $message]);
    }
}
