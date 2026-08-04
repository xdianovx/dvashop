<?php

namespace App\Services\Catalog;

use App\Models\PartType;
use App\Models\ProductCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CatalogStructureAdminService
{
    public function __construct(
        private readonly KnownUniqueConstraintGuard $uniqueConstraints,
        private readonly CatalogRelationIdNormalizer $relationIds,
    ) {}

    public function guardUniqueIdentitySave(Model $record, Closure $save): bool
    {
        return DB::transaction(function () use ($record, $save): bool {
            $this->lockExisting($record);
            [$field, $message, $identifiers] = $this->identityConflictDetails($record);

            return (bool) $this->uniqueConstraints->run($save, $field, $message, $identifiers);
        });
    }

    public function setActive(Model $record, bool $active): Model
    {
        if (! $record instanceof ProductCategory
            && ! $record instanceof PartType
            && ! $record instanceof VehicleMake
            && ! $record instanceof VehicleModel
            && ! $record instanceof VehicleGeneration) {
            $this->fail('record', 'Этот элемент нельзя деактивировать через каталог.');
        }

        return DB::transaction(function () use ($record, $active): Model {
            $locked = $record->newQuery()->withTrashed()->whereKey($record)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['is_active' => $active])->save();

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function saveCategory(ProductCategory $category, array $data): ProductCategory
    {
        return DB::transaction(function () use ($category, $data): ProductCategory {
            $this->lockExisting($category);
            $category->fill($data)->save();

            return $category->refresh();
        });
    }

    public function prepareCategoryForSave(ProductCategory $category): void
    {
        $parentId = $this->relationIds->nullablePositive($category->parent_id, 'parent_id');
        $category->parent_id = $parentId;

        if ($parentId === null) {
            return;
        }

        if ($category->exists && $parentId === (int) $category->getKey()) {
            $this->fail('parent_id', 'Категория не может быть родителем самой себе.');
        }

        $visited = [];
        $cursorId = $parentId;

        while ($cursorId !== null) {
            if (isset($visited[$cursorId])) {
                $this->fail('parent_id', 'Выбранный родитель образует цикл в дереве категорий.');
            }

            $visited[$cursorId] = true;

            if ($category->exists && $cursorId === (int) $category->getKey()) {
                $this->fail('parent_id', 'Нельзя переместить категорию внутрь её потомка.');
            }

            $parent = ProductCategory::withTrashed()
                ->whereKey($cursorId)
                ->lockForUpdate()
                ->first();

            if (! $parent instanceof ProductCategory) {
                $this->fail('parent_id', 'Выбранная родительская категория не существует.');
            }

            if ($parent->trashed()) {
                $this->fail('parent_id', 'Удалённая категория не может быть родительской.');
            }

            $cursorId = $this->relationIds->nullablePositive($parent->parent_id, 'parent_id');
        }
    }

    public function assertCategoryIdentityAvailable(ProductCategory $category): void
    {
        if (ProductCategory::withTrashed()
            ->where('full_slug', $category->full_slug)
            ->when($category->exists, fn ($query) => $query->whereKeyNot($category->getKey()))
            ->exists()) {
            $this->fail('full_slug', 'Полный путь категории уже используется другой категорией.');
        }
    }

    public function deleteCategory(ProductCategory $category): void
    {
        DB::transaction(function () use ($category): void {
            $locked = ProductCategory::query()->whereKey($category)->lockForUpdate()->firstOrFail();
            $this->assertCategoryCanBeDeleted($locked);
            $locked->delete();
        });
    }

    public function assertCategoryCanBeDeleted(ProductCategory $category): void
    {
        if ($category->isForceDeleting()) {
            $this->fail('category', 'Безвозвратное удаление категорий запрещено.');
        }

        if ($category->children()->exists()) {
            $this->fail('category', 'Нельзя удалить категорию, пока у неё есть дочерние категории.');
        }

        if ($category->products()->exists()) {
            $this->fail('category', 'Нельзя удалить категорию, пока к ней привязаны товары.');
        }

        if ($category->partTypes()->exists()) {
            $this->fail('category', 'Нельзя удалить категорию, пока она используется типами деталей.');
        }
    }

    public function restoreCategory(ProductCategory $category): void
    {
        DB::transaction(function () use ($category): void {
            $locked = ProductCategory::withTrashed()->whereKey($category)->lockForUpdate()->firstOrFail();
            $this->assertCategoryCanBeRestored($locked);
            $locked->restore();
        });
    }

    public function assertCategoryCanBeRestored(ProductCategory $category): void
    {
        $this->prepareCategoryForSave($category);
        $category->unsetRelation('parent');
        $category->rebuildPathFields();
        $this->assertCategoryIdentityAvailable($category);
    }

    /** @param array<string, mixed> $data */
    public function savePartType(PartType $partType, array $data): PartType
    {
        return DB::transaction(function () use ($partType, $data): PartType {
            $this->lockExisting($partType);
            $partType->fill($data)->save();

            return $partType->refresh();
        });
    }

    public function assertPartTypeCanBeDeleted(PartType $partType): void
    {
        if ($partType->isForceDeleting()) {
            $this->fail('part_type', 'Безвозвратное удаление типов деталей запрещено.');
        }

        if ($partType->children()->exists() || $partType->products()->exists() || $partType->optionTemplates()->exists()) {
            $this->fail('part_type', 'Нельзя удалить используемый тип детали или узел с дочерними типами.');
        }
    }

    public function deletePartType(PartType $partType): void
    {
        DB::transaction(function () use ($partType): void {
            $locked = PartType::query()->whereKey($partType)->lockForUpdate()->firstOrFail();
            $this->assertPartTypeCanBeDeleted($locked);
            $locked->delete();
        });
    }

    public function restorePartType(PartType $partType): void
    {
        DB::transaction(function () use ($partType): void {
            $locked = PartType::withTrashed()->whereKey($partType)->lockForUpdate()->firstOrFail();
            $this->assertPartTypeCanBeRestored($locked);
            $locked->restore();
        });
    }

    public function assertPartTypeCanBeRestored(PartType $partType): void
    {
        if ($partType->parent_id !== null) {
            $parent = PartType::query()->find($partType->parent_id);

            if (! $parent instanceof PartType) {
                $this->fail('parent_id', 'Нельзя восстановить тип детали без действующего родителя.');
            }
        }

        if ($partType->product_category_id !== null && ! ProductCategory::query()->whereKey($partType->product_category_id)->exists()) {
            $this->fail('product_category_id', 'Нельзя восстановить тип детали без действующей категории магазина.');
        }
    }

    public function assertPartTypeIdentityAvailable(PartType $partType): void
    {
        if (PartType::withTrashed()
            ->where('full_slug', $partType->full_slug)
            ->when($partType->exists, fn ($query) => $query->whereKeyNot($partType->getKey()))
            ->exists()) {
            $this->fail('full_slug', 'Полный путь типа детали уже используется другим типом детали.');
        }
    }

    /** @param array<string, mixed> $data */
    public function saveVehicleMake(VehicleMake $make, array $data): VehicleMake
    {
        return $this->saveVehicleRecord($make, $data);
    }

    /** @param array<string, mixed> $data */
    public function saveVehicleModel(VehicleModel $model, array $data): VehicleModel
    {
        return $this->saveVehicleRecord($model, $data);
    }

    /** @param array<string, mixed> $data */
    public function saveVehicleGeneration(VehicleGeneration $generation, array $data): VehicleGeneration
    {
        return $this->saveVehicleRecord($generation, $data);
    }

    public function prepareVehicleModelForSave(VehicleModel $model): void
    {
        $makeId = $this->relationIds->positive($model->vehicle_make_id, 'vehicle_make_id');
        $model->vehicle_make_id = $makeId;
        $make = VehicleMake::withTrashed()->whereKey($makeId)->lockForUpdate()->first();

        if (! $make instanceof VehicleMake || $make->trashed()) {
            $this->fail('vehicle_make_id', 'Выбранная марка не существует или удалена.');
        }

        if ((! $model->exists || $model->isDirty('vehicle_make_id')) && ! $make->is_active) {
            $this->fail('vehicle_make_id', 'Нельзя назначить неактивную марку.');
        }

        if ($model->exists && $model->isDirty('vehicle_make_id') && $model->generations()->exists()) {
            $this->fail('vehicle_make_id', 'Нельзя перенести модель с поколениями в другую марку.');
        }
    }

    public function assertVehicleMakeIdentityAvailable(VehicleMake $make): void
    {
        if (VehicleMake::withTrashed()
            ->where('norm_key', $make->norm_key)
            ->when($make->exists, fn ($query) => $query->whereKeyNot($make->getKey()))
            ->exists()) {
            $this->fail('norm_key', 'Марка с таким нормализованным ключом уже существует.');
        }
    }

    public function assertVehicleModelIdentityAvailable(VehicleModel $model): void
    {
        $scope = VehicleModel::withTrashed()
            ->where('vehicle_make_id', $model->vehicle_make_id)
            ->when($model->exists, fn ($query) => $query->whereKeyNot($model->getKey()));

        if ((clone $scope)->where('slug', $model->slug)->exists()) {
            $this->fail('slug', 'У этой марки уже существует модель с таким slug.');
        }

        if ((clone $scope)->where('norm_key', $model->norm_key)->exists()) {
            $this->fail('norm_key', 'У этой марки уже существует модель с таким нормализованным ключом.');
        }
    }

    public function prepareVehicleGenerationForSave(VehicleGeneration $generation): void
    {
        $modelId = $this->relationIds->positive($generation->vehicle_model_id, 'vehicle_model_id');
        $generation->vehicle_model_id = $modelId;
        $model = VehicleModel::withTrashed()
            ->whereKey($modelId)
            ->lockForUpdate()
            ->first();

        if (! $model instanceof VehicleModel || $model->trashed()) {
            $this->fail('vehicle_model_id', 'Выбранная модель или её марка не существует либо удалена.');
        }

        $makeId = $this->relationIds->positive($model->vehicle_make_id, 'vehicle_make_id');
        $make = VehicleMake::withTrashed()->whereKey($makeId)->lockForUpdate()->first();

        if (! $make instanceof VehicleMake || $make->trashed()) {
            $this->fail('vehicle_model_id', 'Выбранная модель или её марка не существует либо удалена.');
        }

        if ((! $generation->exists || $generation->isDirty('vehicle_model_id'))
            && (! $model->is_active || ! $make->is_active)) {
            $this->fail('vehicle_model_id', 'Нельзя назначить неактивную модель или марку.');
        }

        if ($generation->exists && $generation->isDirty('vehicle_model_id') && $generation->fitments()->exists()) {
            $this->fail('vehicle_model_id', 'Нельзя перенести поколение, которое используется в применяемости товаров.');
        }
    }

    public function assertVehicleGenerationIdentityAvailable(VehicleGeneration $generation): void
    {
        $scope = VehicleGeneration::withTrashed()
            ->where('vehicle_model_id', $generation->vehicle_model_id)
            ->when($generation->exists, fn ($query) => $query->whereKeyNot($generation->getKey()));

        if ((clone $scope)->where('slug', $generation->slug)->exists()) {
            $this->fail('slug', 'У этой модели уже существует поколение с таким slug.');
        }

        if ((clone $scope)->where('norm_key', $generation->norm_key)->exists()) {
            $this->fail('norm_key', 'У этой модели уже существует поколение с таким нормализованным ключом.');
        }
    }

    public function assertVehicleCanBeDeleted(Model $record): void
    {
        if (method_exists($record, 'isForceDeleting') && $record->isForceDeleting()) {
            $this->fail('vehicle', 'Безвозвратное удаление автомобильного справочника запрещено.');
        }

        $used = match (true) {
            $record instanceof VehicleMake => $record->models()->exists(),
            $record instanceof VehicleModel => $record->generations()->exists(),
            $record instanceof VehicleGeneration => $record->fitments()->exists(),
            default => true,
        };

        if ($used) {
            $this->fail('vehicle', 'Нельзя удалить используемый элемент автомобильного справочника.');
        }
    }

    public function deleteVehicle(Model $record): void
    {
        DB::transaction(function () use ($record): void {
            $locked = $record->newQuery()->whereKey($record)->lockForUpdate()->firstOrFail();
            $this->assertVehicleCanBeDeleted($locked);
            $locked->delete();
        });
    }

    public function restoreVehicle(Model $record): void
    {
        DB::transaction(function () use ($record): void {
            $locked = $record->newQuery()->withTrashed()->whereKey($record)->lockForUpdate()->firstOrFail();
            $this->assertVehicleCanBeRestored($locked);
            $locked->restore();
        });
    }

    public function assertVehicleCanBeRestored(Model $record): void
    {
        if ($record instanceof VehicleModel) {
            $make = VehicleMake::query()->find($record->vehicle_make_id);

            if (! $make instanceof VehicleMake) {
                $this->fail('vehicle_make_id', 'Нельзя восстановить модель без действующей марки.');
            }
        }

        if ($record instanceof VehicleGeneration) {
            $model = VehicleModel::query()->find($record->vehicle_model_id);

            if (! $model instanceof VehicleModel) {
                $this->fail('vehicle_model_id', 'Нельзя восстановить поколение без действующей модели.');
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function saveVehicleRecord(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $this->lockExisting($record);
            $record->fill($data)->save();

            return $record->refresh();
        });
    }

    private function lockExisting(Model $record): void
    {
        if ($record->exists) {
            $record->newQuery()
                ->withTrashed()
                ->whereKey($record->getKey())
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    /** @return array{string, string, array<int, string>} */
    private function identityConflictDetails(Model $record): array
    {
        return match (true) {
            $record instanceof ProductCategory => [
                'full_slug',
                'Полный путь категории уже используется другой категорией.',
                ['product_categories.full_slug', 'product_categories_full_slug_unique'],
            ],
            $record instanceof PartType => [
                'full_slug',
                'Полный путь типа детали уже используется другим типом детали.',
                ['part_types.full_slug', 'part_types_full_slug_unique'],
            ],
            $record instanceof VehicleMake => [
                'norm_key',
                'Марка с таким нормализованным ключом уже существует.',
                ['vehicle_makes.norm_key', 'vehicle_makes_norm_key_unique'],
            ],
            $record instanceof VehicleModel => [
                'vehicle_model',
                'У этой марки уже существует модель с таким slug или нормализованным ключом.',
                [
                    'vehicle_models.vehicle_make_id, vehicle_models.slug',
                    'vehicle_models.vehicle_make_id, vehicle_models.norm_key',
                    'vehicle_models_vehicle_make_id_slug_unique',
                    'vehicle_models_vehicle_make_id_norm_key_unique',
                ],
            ],
            $record instanceof VehicleGeneration => [
                'vehicle_generation',
                'У этой модели уже существует поколение с таким slug или нормализованным ключом.',
                [
                    'vehicle_generations.vehicle_model_id, vehicle_generations.slug',
                    'vehicle_generations.vehicle_model_id, vehicle_generations.norm_key',
                    'vehicle_generations_vehicle_model_id_slug_unique',
                    'vehicle_generations_vehicle_model_id_norm_key_unique',
                ],
            ],
            default => ['record', 'Такой элемент каталога уже существует.', []],
        };
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
