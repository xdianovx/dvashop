<?php

namespace Database\Seeders;

use App\Enums\LegalDocumentCode;
use App\Models\LegalDocument;
use Database\Seeders\Concerns\FillsMissingSeederAttributes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LegalDocumentsSeeder extends Seeder
{
    use FillsMissingSeederAttributes;

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (LegalDocumentCode::cases() as $code) {
                $document = LegalDocument::query()->firstOrNew(['code' => $code->value]);
                $this->fillMissing($document, [
                    'title' => $code->label(),
                    'body' => null,
                    'is_active' => false,
                ])->save();
            }
        });
    }
}
