<?php

namespace App\Services\Search;

use App\Jobs\RefreshCatalogSearchDependencies;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;
use Throwable;

final class CatalogSearchAliasFileService
{
    private const VERSION = 1;

    private const REINDEX_CHUNK = 100;

    /** @return array{version:int,makes:list<array<string,mixed>>,models:list<array<string,mixed>>} */
    public function exportData(): array
    {
        $makes = VehicleMake::withTrashed()->orderBy('id')->get(['id', 'title', 'norm_key', 'search_aliases']);
        $makeContext = $makes->keyBy('id');
        $models = VehicleModel::withTrashed()->orderBy('id')->get(['id', 'vehicle_make_id', 'title', 'norm_key', 'search_aliases']);

        return [
            'version' => self::VERSION,
            'makes' => $makes->map(static fn (VehicleMake $make): array => [
                'id' => (int) $make->id,
                'norm_key' => (string) $make->norm_key,
                'title' => (string) $make->title,
                'aliases' => $make->search_aliases ?? [],
            ])->values()->all(),
            'models' => $models->map(static function (VehicleModel $model) use ($makeContext): array {
                /** @var VehicleMake|null $make */
                $make = $makeContext->get($model->vehicle_make_id);

                return [
                    'id' => (int) $model->id,
                    'vehicle_make_id' => (int) $model->vehicle_make_id,
                    'make_norm_key' => (string) ($make?->norm_key ?? ''),
                    'make' => (string) ($make?->title ?? ''),
                    'norm_key' => (string) $model->norm_key,
                    'title' => (string) $model->title,
                    'aliases' => $model->search_aliases ?? [],
                ];
            })->values()->all(),
        ];
    }

    public function exportTo(string $file): string
    {
        $path = $this->path($file);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create alias export directory: '.$directory);
        }
        $json = json_encode($this->exportData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write alias export file: '.$path);
        }

        return $path;
    }

    /**
     * Two-phase import. Phase A resolves and validates every row without writes.
     * Phase B applies the complete change plan in one transaction only when Phase A
     * has no errors. Reindex jobs are dispatched strictly after a successful commit.
     *
     * @return array{dry_run:bool,created:int,updated:int,unchanged:int,errors:int,error_messages:list<string>,reindex_jobs:int}
     */
    public function importFrom(string $file, bool $dryRun = false): array
    {
        $payload = $this->readPayload($file);
        $summary = [
            'dry_run' => $dryRun,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'error_messages' => [],
            'reindex_jobs' => 0,
        ];

        // Phase A: parse, resolve identity, normalize aliases and calculate every
        // intended change. Nothing is written and no jobs are dispatched here.
        $plans = [];
        $seen = [];
        foreach (['make' => $payload['makes'], 'model' => $payload['models']] as $type => $rows) {
            foreach ($rows as $index => $row) {
                $plan = $this->planRow($type, $index, $row, $summary);
                if ($plan === null) {
                    continue;
                }
                $key = $type.':'.$plan['id'];
                if (isset($seen[$key])) {
                    $this->recordError($summary, $type, $index, 'duplicate entity row in import file');

                    continue;
                }
                $seen[$key] = true;
                $plans[] = $plan;
            }
        }

        if ($summary['errors'] > 0 || $dryRun) {
            return $summary;
        }

        $changedMakeIds = [];
        $changedModelIds = [];

        // Phase B: apply the already validated plan atomically. Identities and the
        // expected current aliases are rechecked under row locks to reject races.
        try {
            DB::transaction(function () use ($plans, &$changedMakeIds, &$changedModelIds): void {
                foreach ($plans as $plan) {
                    if (! $plan['changed']) {
                        continue;
                    }

                    $record = $this->findRecord($plan['type'], $plan['id'], true);
                    if (! $record) {
                        throw new RuntimeException($plan['type'].' entity disappeared after validation');
                    }
                    $this->assertIdentity($plan['type'], $record, $plan['row']);
                    $current = SearchText::aliases($record->search_aliases ?? []);
                    if ($current !== $plan['current']) {
                        throw new RuntimeException($plan['type'].' search_aliases changed after validation');
                    }

                    $record->forceFill(['search_aliases' => $plan['aliases']])->saveQuietly();
                    if ($plan['type'] === 'make') {
                        $changedMakeIds[] = $plan['id'];
                    } else {
                        $changedModelIds[] = $plan['id'];
                    }
                }
            });
        } catch (ValidationException $exception) {
            $this->recordError($summary, 'transaction', 0, implode(' ', $exception->validator->errors()->all()));

            return $summary;
        } catch (Throwable $exception) {
            $this->recordError($summary, 'transaction', 0, $exception->getMessage());

            return $summary;
        }

        if (config('scout.driver') === 'meilisearch') {
            foreach (array_chunk(array_values(array_unique($changedMakeIds)), self::REINDEX_CHUNK) as $ids) {
                if ($ids !== []) {
                    RefreshCatalogSearchDependencies::dispatch(VehicleMake::class, $ids);
                    $summary['reindex_jobs']++;
                }
            }
            foreach (array_chunk(array_values(array_unique($changedModelIds)), self::REINDEX_CHUNK) as $ids) {
                if ($ids !== []) {
                    RefreshCatalogSearchDependencies::dispatch(VehicleModel::class, $ids);
                    $summary['reindex_jobs']++;
                }
            }
        }

        return $summary;
    }

    /** @return array{version:int,makes:list<mixed>,models:list<mixed>} */
    private function readPayload(string $file): array
    {
        $path = $this->path($file);
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Alias import file is not readable: '.$path);
        }
        try {
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Alias import file is not valid JSON: '.$exception->getMessage(), previous: $exception);
        }
        if (! is_array($payload) || ($payload['version'] ?? null) !== self::VERSION || ! is_array($payload['makes'] ?? null) || ! is_array($payload['models'] ?? null)) {
            throw new RuntimeException('Alias import file must contain version=1 plus makes/models arrays.');
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array{type:string,id:int,row:array<string,mixed>,aliases:list<string>,current:list<string>,changed:bool}|null
     */
    private function planRow(string $type, int|string $index, mixed $row, array &$summary): ?array
    {
        try {
            if (! is_array($row)) {
                throw new RuntimeException('row must be an object');
            }
            $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new RuntimeException('id must be a positive integer');
            }
            if (! is_string($row['norm_key'] ?? null) || $row['norm_key'] === '') {
                throw new RuntimeException('norm_key is required');
            }
            if (! is_string($row['title'] ?? null) || $row['title'] === '') {
                throw new RuntimeException('title is required');
            }
            if (! array_key_exists('aliases', $row) || ! is_array($row['aliases'])) {
                throw new RuntimeException('aliases must be an array');
            }

            $record = $this->findRecord($type, (int) $id);
            if (! $record) {
                throw new RuntimeException($type.' entity does not exist');
            }
            $this->assertIdentity($type, $record, $row);

            $aliases = SearchText::aliases($row['aliases']);
            $current = SearchText::aliases($record->search_aliases ?? []);
            if ($current === $aliases) {
                $summary['unchanged']++;
            } else {
                $summary[$current === [] && $aliases !== [] ? 'created' : 'updated']++;
            }

            return [
                'type' => $type,
                'id' => (int) $id,
                'row' => $row,
                'aliases' => $aliases,
                'current' => $current,
                'changed' => $current !== $aliases,
            ];
        } catch (ValidationException $exception) {
            $this->recordError($summary, $type, $index, implode(' ', $exception->validator->errors()->all()));
        } catch (Throwable $exception) {
            $this->recordError($summary, $type, $index, $exception->getMessage());
        }

        return null;
    }

    private function findRecord(string $type, int $id, bool $lock = false): ?Model
    {
        $query = $type === 'make'
            ? VehicleMake::withTrashed()
            : VehicleModel::withTrashed()->with('make');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->find($id);
    }

    /** @param array<string,mixed> $row */
    private function assertIdentity(string $type, Model $record, array $row): void
    {
        if ((string) $record->norm_key !== (string) $row['norm_key'] || (string) $record->title !== (string) $row['title']) {
            throw new RuntimeException('stable identity mismatch for id/norm_key/title');
        }
        if ($type === 'model') {
            if (! $record instanceof VehicleModel) {
                throw new RuntimeException('entity type mismatch');
            }
            $makeId = filter_var($row['vehicle_make_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($makeId === false || (int) $record->vehicle_make_id !== (int) $makeId) {
                throw new RuntimeException('vehicle_make_id context mismatch');
            }
            if (! is_string($row['make_norm_key'] ?? null) || (string) $record->make?->norm_key !== (string) $row['make_norm_key']) {
                throw new RuntimeException('make_norm_key context mismatch');
            }
        }
    }

    private function recordError(array &$summary, string $type, int|string $index, string $message): void
    {
        $summary['errors']++;
        $summary['error_messages'][] = $type.'['.$index.']: '.$message;
    }

    private function path(string $file): string
    {
        if ($file === '') {
            throw new RuntimeException('Alias file path must not be empty.');
        }

        return str_starts_with($file, DIRECTORY_SEPARATOR) ? $file : base_path($file);
    }
}
