<?php

namespace App\Services\Legal;

use App\Models\LegalDocument;
use App\Services\Media\MediaFileCleanupService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class LegalDocumentPdfStorage
{
    public const DIRECTORY = 'uploads/legal-documents';

    public const MAX_SIZE_KB = 10240;

    public static function isSafePath(mixed $path): bool
    {
        return is_string($path)
            && preg_match('~^uploads/legal-documents/[a-zA-Z0-9-]+\.pdf$~D', $path) === 1;
    }

    public function store(mixed $file, LegalDocument $document, bool $required): ?string
    {
        if ($file instanceof UploadedFile) {
            Validator::make(['pdf_path' => $file], [
                'pdf_path' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'extensions:pdf', 'max:'.self::MAX_SIZE_KB],
            ], [
                'pdf_path.mimes' => 'Загрузите PDF-файл.',
                'pdf_path.mimetypes' => 'Загрузите PDF-файл.',
                'pdf_path.extensions' => 'Расширение файла должно быть .pdf.',
                'pdf_path.max' => 'PDF-файл не должен превышать 10 МБ.',
            ])->validate();

            $path = $file->storeAs(self::DIRECTORY, Str::uuid().'.pdf', 'public');
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Не удалось сохранить PDF-файл.');
            }

            DB::afterRollBack(fn () => app(MediaFileCleanupService::class)->deletePath($path));

            return $path;
        }

        if (blank($file) && ! $required) {
            return null;
        }

        // Existing paths are accepted only for the document being edited.
        if (! self::isSafePath($file) || $file !== $document->pdf_path || ! Storage::disk('public')->exists($file)) {
            throw ValidationException::withMessages(['pdf_path' => 'Загрузите PDF-файл для этого документа.']);
        }

        return $file;
    }
}
