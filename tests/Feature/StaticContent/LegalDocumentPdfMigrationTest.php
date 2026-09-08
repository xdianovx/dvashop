<?php

use App\Enums\LegalDocumentContentType;
use App\Models\LegalDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('PDF migration defaults legacy documents to Page and reverses without changing their body', function (): void {
    $original = DB::getDefaultConnection();
    $connection = 'legal_pdf_upgrade';
    config(["database.connections.{$connection}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);
    Schema::clearResolvedInstance('db.schema');
    try {
        (require database_path('migrations/2026_08_07_000200_create_legal_documents_table.php'))->up();
        DB::table('legal_documents')->insert(['code' => 'privacy_policy', 'title' => 'Legacy', 'body' => '<p>Original</p>', 'is_active' => true]);
        $before = (array) DB::table('legal_documents')->sole();
        $migration = require database_path('migrations/2026_09_08_000100_add_pdf_content_to_legal_documents.php');
        $migration->up();
        $document = LegalDocument::query()->sole();
        expect($document->content_type)->toBe(LegalDocumentContentType::Page)->and($document->pdf_path)->toBeNull()
            ->and($document->body)->toBe('<p>Original</p>')->and($document->publicDestination())->toBe(route('legal.privacy-policy'));
        $migration->down();
        expect(Schema::hasColumn('legal_documents', 'pdf_path'))->toBeFalse()
            ->and(Schema::hasColumn('legal_documents', 'content_type'))->toBeFalse()
            ->and((array) DB::table('legal_documents')->sole())->toBe($before);
        $migration->up();
        expect(LegalDocument::query()->sole()->body)->toBe('<p>Original</p>');
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
