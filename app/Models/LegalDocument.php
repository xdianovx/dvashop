<?php

namespace App\Models;

use App\Enums\LegalDocumentCode;
use App\Enums\LegalDocumentContentType;
use App\Services\Legal\LegalDocumentPdfStorage;
use App\Services\Legal\LegalRichContentSanitizer;
use App\Services\Media\MediaFileCleanupService;
use App\Services\Media\MediaUrlService;
use App\Services\Storefront\LegalDocumentRouteMap;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['code', 'title', 'body', 'is_active', 'content_type', 'pdf_path'])]
class LegalDocument extends Model
{
    protected $attributes = ['content_type' => 'page'];

    protected static function booted(): void
    {
        static::saving(function (self $document): void {
            $rawCode = $document->getAttributes()['code'] ?? null;

            if (! is_string($rawCode) || LegalDocumentCode::tryFrom($rawCode) === null) {
                throw ValidationException::withMessages(['code' => 'Выбран неизвестный системный документ.']);
            }

            if ($document->exists && $document->isDirty('code')) {
                throw ValidationException::withMessages(['code' => 'Системный код документа нельзя изменять.']);
            }

            $document->title = trim((string) $document->title);
            $document->body = app(LegalRichContentSanitizer::class)->sanitize($document->body);

            if (strip_tags($document->title) !== $document->title) {
                throw ValidationException::withMessages(['title' => 'Название документа должно содержать обычный текст без HTML.']);
            }

            if ($document->title === '') {
                throw ValidationException::withMessages(['title' => 'Название документа обязательно.']);
            }

            $type = LegalDocumentContentType::tryFrom((string) ($document->getAttributes()['content_type'] ?? ''));
            if ($type === null) {
                throw ValidationException::withMessages(['content_type' => 'Выберите страницу или PDF-файл.']);
            }

            if ($type === LegalDocumentContentType::Page) {
                $document->pdf_path = null;
                if ($document->body === null) {
                    $document->is_active = false;
                }
            } elseif (($document->is_active || filled($document->pdf_path))
                && (! LegalDocumentPdfStorage::isSafePath($document->pdf_path)
                    || app(MediaUrlService::class)->publicDiskUrl($document->pdf_path) === null)) {
                throw ValidationException::withMessages(['pdf_path' => 'Загрузите PDF-файл для этого документа.']);
            }
        });

        static::saved(function (self $document): void {
            $oldPath = $document->getRawOriginal('pdf_path');
            if ($document->wasChanged('pdf_path') && $oldPath !== $document->pdf_path
                && LegalDocumentPdfStorage::isSafePath($oldPath)) {
                app(MediaFileCleanupService::class)->deletePathAfterCommit($oldPath);
            }
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_active', true)->where(function (Builder $query): void {
            $query->where(fn (Builder $page) => $page->where('content_type', LegalDocumentContentType::Page->value)
                ->whereNotNull('body')->where('body', '!=', ''))
                ->orWhere(fn (Builder $pdf) => $pdf->where('content_type', LegalDocumentContentType::Pdf->value)
                    ->whereNotNull('pdf_path')->where('pdf_path', '!=', ''));
        });
    }

    public function publicDestination(): ?string
    {
        if (! $this->is_active) {
            return null;
        }

        if ($this->content_type === LegalDocumentContentType::Pdf) {
            return LegalDocumentPdfStorage::isSafePath($this->pdf_path)
                ? app(MediaUrlService::class)->publicDiskUrl($this->pdf_path)
                : null;
        }

        return filled($this->body) ? app(LegalDocumentRouteMap::class)->url($this->code) : null;
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['legal_document' => 'Системный документ нельзя удалить.']);
    }

    public function forceDelete(): never
    {
        throw ValidationException::withMessages(['legal_document' => 'Системный документ нельзя удалить безвозвратно.']);
    }

    public function replicate(?array $except = null)
    {
        throw ValidationException::withMessages(['legal_document' => 'Системный документ нельзя копировать.']);
    }

    /** @return Attribute<LegalDocumentCode, LegalDocumentCode|string> */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): LegalDocumentCode {
                $code = is_string($value) ? LegalDocumentCode::tryFrom($value) : null;

                if ($code === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный системный документ.']);
                }

                return $code;
            },
            set: function (mixed $value): string {
                $raw = $value instanceof LegalDocumentCode ? $value->value : $value;

                if (! is_string($raw) || LegalDocumentCode::tryFrom($raw) === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный системный документ.']);
                }

                return $raw;
            },
        );
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'content_type' => LegalDocumentContentType::class];
    }
}
