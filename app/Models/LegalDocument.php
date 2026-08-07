<?php

namespace App\Models;

use App\Enums\LegalDocumentCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['code', 'title', 'body', 'is_active'])]
class LegalDocument extends Model
{
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
            $document->body = is_string($document->body) ? trim($document->body) : null;
            $document->body = $document->body === '' ? null : $document->body;

            foreach (['title' => $document->title, 'body' => $document->body] as $field => $value) {
                if (is_string($value) && strip_tags($value) !== $value) {
                    throw ValidationException::withMessages([$field => 'Документ должен содержать обычный текст без HTML.']);
                }
            }

            if ($document->title === '') {
                throw ValidationException::withMessages(['title' => 'Название документа обязательно.']);
            }

            if ($document->body === null) {
                $document->is_active = false;
            }
        });
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
        return ['is_active' => 'boolean'];
    }
}
