<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Database backstop for bulk SQL, relation editors and concurrent writers. */
final class CatalogImportDatabaseGuards
{
    private const TABLES = ['products', 'product_variants', 'product_fitments', 'product_images',
        'product_variant_option_values', 'catalog_configurations', 'product_option_templates',
        'product_option_template_items', 'product_option_groups', 'product_option_values',
        'part_types', 'product_categories', 'vehicle_generations', 'vehicle_models', 'vehicle_makes',
        'catalog_import_protections', 'catalog_matrix_sources', 'catalog_safe_partial_items'];

    public function install(?string $onlyTable = null, ?string $onlyEvent = null): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Защита safe_partial требует SQLite или MySQL.');
        }
        CatalogImportContext::register(DB::connection());
        foreach (self::TABLES as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                if (($onlyTable !== null && $table !== $onlyTable) || ($onlyEvent !== null && $event !== $onlyEvent)) {
                    continue;
                }
                $refs = $event === 'UPDATE' ? ['OLD', 'NEW'] : [$event === 'INSERT' ? 'NEW' : 'OLD'];
                $queries = array_filter(array_map(fn ($ref) => $this->query($table, $ref, $driver), $refs));
                $name = $this->name($table, $event);
                $immutable = $table === 'catalog_import_protections' && $event !== 'INSERT';
                $inventoryOnly = $table === 'product_variants' && $event === 'UPDATE'
                    ? $this->inventoryOnly($driver) : null;
                $archiveOnly = $table === 'products' && $event === 'UPDATE' ? $this->archiveOnly($driver) : null;
                if ($driver === 'sqlite') {
                    $conditions = array_map(fn ($q) => 'EXISTS ('.$q.')', $queries);
                    $protected = $immutable ? '1' : ($conditions ? implode(' OR ', $conditions) : '0');
                    if ($archiveOnly !== null) {
                        $protected = "($protected) AND NOT ($archiveOnly)";
                    }
                    $when = $inventoryOnly === null ? '' : " WHEN NOT ($inventoryOnly)";
                    DB::unprepared("CREATE TRIGGER $name BEFORE $event ON $table$when BEGIN
                        SELECT RAISE(ABORT, 'catalog reserved by safe_partial')
                        WHERE COALESCE((SELECT safe_partial_run_id FROM catalog_price_locks WHERE id=1),0) NOT IN (0,catalog_safe_partial_owner());
                        SELECT RAISE(ABORT, 'catalog import protection') WHERE $protected;
                    END");
                } else {
                    // A locking read sees protections committed while this writer waited.
                    // Lock only protection rows. Locking the trigger's subject table
                    // through a join is forbidden by MySQL (error 1442).
                    $checks = '';
                    foreach ($queries as $query) {
                        $query = preg_replace('/^SELECT cp.product_id /', 'SELECT COUNT(*) INTO protected_rows ', $query);
                        $checks .= $query." FOR UPDATE OF cp; IF protected_rows > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='catalog import protection'; END IF;";
                    }
                    if ($immutable) {
                        $checks = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='catalog import protection is permanent';";
                    }
                    if ($archiveOnly !== null) {
                        $checks = "IF NOT ($archiveOnly) THEN $checks END IF;";
                    }
                    // Inventory is serialized by the variant row itself. Never acquire
                    // the catalog mutex or scan protections for this operational update.
                    if ($inventoryOnly !== null) {
                        $checks = "IF NOT ($inventoryOnly) THEN ".$this->ownerCheck().$checks.' END IF;';
                    } else {
                        $checks = $this->ownerCheck().$checks;
                    }
                    DB::unprepared("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW BEGIN
                        DECLARE owner_id BIGINT DEFAULT 0;
                        DECLARE protected_rows BIGINT DEFAULT 0;
                        $checks
                    END");
                }
            }
        }
    }

    /** Install the replacement before dropping the old trigger: no unguarded DDL gap. */
    public function upgradeInventoryGuard(): void
    {
        $name = $this->name('product_variants', 'UPDATE');
        $exists = DB::getDriverName() === 'mysql'
            ? DB::table('information_schema.triggers')->where('trigger_schema', DB::connection()->getDatabaseName())->where('trigger_name', $name)->exists()
            : DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->exists();
        if (! $exists) {
            $this->install('product_variants', 'UPDATE');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS '.$this->legacyVariantUpdateName());
    }

    public function fullArchiveReady(): bool
    {
        $name = $this->name('products', 'UPDATE');

        return DB::getDriverName() === 'mysql'
            ? DB::table('information_schema.triggers')->where('trigger_schema', DB::connection()->getDatabaseName())->where('trigger_name', $name)->exists()
            : DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->exists();
    }

    public function upgradeProductArchiveGuard(): void
    {
        if (! $this->fullArchiveReady()) {
            $this->install('products', 'UPDATE');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS cip_'.substr(hash('sha256', 'products'), 0, 12).'_update');
    }

    private function archiveOnly(string $driver): string
    {
        $same = $this->sameColumns('products', ['status', 'import_present', 'updated_at'], $driver);
        $owner = $driver === 'mysql' ? 'COALESCE(@catalog_full_archive_run_id,0)' : 'catalog_full_archive_owner()';
        $nonce = $driver === 'mysql' ? "COALESCE(@catalog_full_archive_nonce,'')" : 'catalog_full_archive_nonce()';
        $extract = fn ($key) => $driver === 'mysql'
            ? "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.prepared_rows, CONCAT('$.full_scope.archives.\"',OLD.id,'\".$key'))),'null')"
            : "json_extract(r.prepared_rows, '$.full_scope.archives.\"' || OLD.id || '\".$key')";
        $equal = fn ($field) => $driver === 'mysql'
            ? '(BINARY CAST(OLD.`'.$field.'` AS CHAR) <=> BINARY '.$extract($field).')'
            : '(CAST(OLD.`'.$field.'` AS TEXT) IS CAST('.$extract($field).' AS TEXT))';
        $nonceField = $driver === 'mysql' ? "JSON_UNQUOTE(JSON_EXTRACT(r.prepared_rows,'$.full_archive_nonce'))" : "json_extract(r.prepared_rows,'$.full_archive_nonce')";

        return "($same) AND NEW.status='archived' AND NEW.import_present=0 AND OLD.import_source='catalog'
            AND (OLD.publication_override IS NULL OR OLD.publication_override='force_active')
            AND $owner > 0 AND EXISTS(SELECT 1 FROM import_runs r WHERE r.id=$owner AND r.import_mode='full'
                AND r.status='running_rows' AND $nonceField=$nonce AND $nonce<>'' AND ".implode(' AND ', array_map($equal, ['import_key', 'status', 'import_present', 'updated_at'])).')';
    }

    private function inventoryOnly(string $driver): string
    {
        // A newly added column is guarded by default when triggers are rebuilt.
        // stock_status, price, options, bindings, flags and identity are NOT exempt.
        return $this->sameColumns('product_variants', ['stock_quantity', 'updated_at'], $driver);
    }

    private function sameColumns(string $table, array $except, string $driver): string
    {
        return implode(' AND ', array_map(fn ($column) => $driver === 'mysql'
            ? "(BINARY OLD.`$column` <=> BINARY NEW.`$column`)" : "(OLD.`$column` IS NEW.`$column` COLLATE BINARY)",
            array_values(array_diff(Schema::getColumnListing($table), $except))));
    }

    private function ownerCheck(): string
    {
        return "SELECT COALESCE(safe_partial_run_id,0) INTO owner_id FROM catalog_price_locks WHERE id=1 FOR UPDATE;
            IF owner_id <> 0 AND owner_id <> COALESCE(@catalog_safe_partial_run_id,0) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='catalog reserved by safe_partial';
            END IF;";
    }

    private function legacyVariantUpdateName(): string
    {
        return 'cip_'.substr(hash('sha256', 'product_variants'), 0, 12).'_update';
    }

    public function uninstall(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.$this->legacyVariantUpdateName());
        DB::unprepared('DROP TRIGGER IF EXISTS cip_'.substr(hash('sha256', 'products'), 0, 12).'_update');
        foreach (self::TABLES as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$this->name($table, $event));
            }
        }
    }

    private function name(string $table, string $event): string
    {
        return 'cip_'.substr(hash('sha256', $table), 0, 12).'_'.strtolower($event)
            .($table === 'product_variants' && $event === 'UPDATE' ? '_inventory_v2' : '')
            .($table === 'products' && $event === 'UPDATE' ? '_archive_v3' : '');
    }

    private function query(string $table, string $r, string $driver): ?string
    {
        $base = 'SELECT cp.product_id FROM catalog_import_protections cp';
        $products = $base.' JOIN products p ON p.id=cp.product_id';
        $variants = $base.' JOIN product_variants v ON v.product_id=cp.product_id';
        $templates = $products.' LEFT JOIN product_variants v ON v.product_id=p.id LEFT JOIN catalog_configurations c ON c.id=v.catalog_configuration_id';
        $path = fn ($field) => $driver === 'mysql' ? "CONCAT($r.$field, '/%')" : "$r.$field || '/%'";

        return match ($table) {
            'products' => "$base WHERE cp.product_id=$r.id",
            'product_variants', 'product_fitments', 'product_images' => "$base WHERE cp.product_id=$r.product_id",
            'product_variant_option_values' => "$variants WHERE v.id=$r.product_variant_id",
            'catalog_configurations' => "$products LEFT JOIN product_variants v ON v.product_id=p.id
                LEFT JOIN product_option_templates t ON t.id=p.product_option_template_id
                WHERE p.part_type_id=$r.part_type_id OR t.part_type_id=$r.part_type_id OR v.catalog_configuration_id=$r.id",
            'product_option_templates' => "$templates WHERE p.product_option_template_id=$r.id OR c.product_option_template_id=$r.id",
            'product_option_template_items' => "$templates WHERE p.product_option_template_id=$r.product_option_template_id OR c.product_option_template_id=$r.product_option_template_id",
            'product_option_groups' => "$templates LEFT JOIN product_option_template_items ti ON ti.product_option_template_id=p.product_option_template_id OR ti.product_option_template_id=c.product_option_template_id
                LEFT JOIN product_variant_option_values ov ON ov.product_variant_id=v.id
                WHERE ti.product_option_group_id=$r.id OR ov.product_option_group_id=$r.id",
            'product_option_values' => "$templates LEFT JOIN product_option_template_items ti ON ti.product_option_template_id=p.product_option_template_id OR ti.product_option_template_id=c.product_option_template_id
                LEFT JOIN product_variant_option_values ov ON ov.product_variant_id=v.id
                WHERE ti.product_option_value_id=$r.id OR ov.product_option_value_id=$r.id",
            'part_types' => "$templates LEFT JOIN product_option_templates t ON t.id=p.product_option_template_id
                JOIN part_types pt ON pt.id=p.part_type_id OR pt.id=t.part_type_id OR pt.id=c.part_type_id
                WHERE pt.id=$r.id OR pt.full_slug LIKE ".$path('full_slug'),
            'product_categories' => "$products JOIN product_categories pc ON pc.id=p.product_category_id WHERE pc.id=$r.id OR pc.full_slug LIKE ".$path('full_slug'),
            'vehicle_generations' => "$base JOIN product_fitments f ON f.product_id=cp.product_id WHERE f.vehicle_generation_id=$r.id",
            'vehicle_models' => "$base JOIN product_fitments f ON f.product_id=cp.product_id JOIN vehicle_generations g ON g.id=f.vehicle_generation_id WHERE g.vehicle_model_id=$r.id",
            'vehicle_makes' => "$base JOIN product_fitments f ON f.product_id=cp.product_id JOIN vehicle_generations g ON g.id=f.vehicle_generation_id JOIN vehicle_models m ON m.id=g.vehicle_model_id WHERE m.vehicle_make_id=$r.id",
            default => null,
        };
    }
}
