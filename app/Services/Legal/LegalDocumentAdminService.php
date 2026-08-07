<?php

namespace App\Services\Legal;

use App\Enums\AdminPermission;
use App\Enums\LegalDocumentCode;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LegalDocumentAdminService
{
    /** @return list<string> */
    private function fixedCodes(): array
    {
        return array_column(LegalDocumentCode::cases(), 'value');
    }

    /** @return array{documents:list<array<string, mixed>>} */
    public function state(): array
    {
        $documents = LegalDocument::query()
            ->whereIn('code', $this->fixedCodes())
            ->get()
            ->keyBy(
                fn (LegalDocument $document): string => $document->code->value,
            );

        $missing = collect(LegalDocumentCode::cases())
            ->reject(fn (LegalDocumentCode $code): bool => $documents->has($code->value))
            ->map(fn (LegalDocumentCode $code): string => $code->value)
            ->values()
            ->all();

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'documents' => 'Не найдены системные документы: '.implode(', ', $missing).'.',
            ]);
        }

        return [
            'documents' => array_map(function (LegalDocumentCode $code) use ($documents): array {
                /** @var LegalDocument $document */
                $document = $documents->get($code->value);

                return [
                    'id' => $document->getKey(),
                    '_label' => $code->label(),
                    'title' => $document->title,
                    'body' => $document->body,
                    'is_active' => $document->is_active,
                ];
            }, LegalDocumentCode::cases()),
        ];
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, array $data): void
    {
        $this->authorize($actor);
        $this->rejectUnexpected($data, ['documents'], 'data');
        $rows = $data['documents'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw ValidationException::withMessages(['documents' => 'Передайте полный список системных документов.']);
        }

        DB::transaction(function () use ($rows): void {
            $documents = LegalDocument::query()
                ->whereIn('code', $this->fixedCodes())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($documents->count() !== count(LegalDocumentCode::cases()) || count($rows) !== $documents->count()) {
                throw ValidationException::withMessages(['documents' => 'Форма должна содержать ровно четыре системных документа.']);
            }

            $byId = $documents->keyBy(fn (LegalDocument $document): int => (int) $document->getKey());
            $seen = [];

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    throw ValidationException::withMessages(["documents.{$index}" => 'Данные документа должны быть массивом.']);
                }

                $this->rejectUnexpected($row, ['id', 'title', 'body', 'is_active'], "documents.{$index}");
                $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

                if ($id === false || isset($seen[$id]) || ! $byId->has($id)) {
                    throw ValidationException::withMessages(["documents.{$index}.id" => 'Системный документ не найден или указан повторно.']);
                }
                $seen[$id] = true;

                /** @var LegalDocument $document */
                $document = $byId->get($id);
                $candidate = [
                    'code' => $document->code->value,
                    'title' => is_string($row['title'] ?? null) ? trim($row['title']) : $row['title'] ?? null,
                    'body' => is_string($row['body'] ?? null) ? trim($row['body']) : $row['body'] ?? null,
                    'is_active' => $row['is_active'] ?? null,
                ];
                $candidate['body'] = $candidate['body'] === '' ? null : $candidate['body'];

                $plainText = function (string $attribute, mixed $value, callable $fail): void {
                    if (is_string($value) && strip_tags($value) !== $value) {
                        $fail('Поле «:attribute» должно содержать обычный текст без HTML.');
                    }
                };

                $validated = Validator::make($candidate, [
                    'code' => ['required', Rule::enum(LegalDocumentCode::class)],
                    'title' => ['required', 'string', 'max:255', $plainText],
                    'body' => ['nullable', 'string', 'max:60000', $plainText],
                    'is_active' => ['required', 'boolean'],
                ], [
                    'required' => 'Поле «:attribute» обязательно.',
                    'string' => 'Поле «:attribute» должно быть строкой.',
                    'max' => 'Поле «:attribute» слишком длинное.',
                    'boolean' => 'Поле «:attribute» должно быть логическим значением.',
                ])->validate();

                if ($validated['body'] === null) {
                    $validated['is_active'] = false;
                }

                unset($validated['code']);
                $validated['is_active'] = (bool) $validated['is_active'];
                $document->fill($validated)->save();
            }

            if (count($seen) !== $documents->count()) {
                throw ValidationException::withMessages(['documents' => 'В форме пропущен системный документ.']);
            }
        });
    }

    private function authorize(User $actor): void
    {
        if (! $actor->canPerformAdminAction(AdminPermission::ManageStaticContent)) {
            throw new AuthorizationException('Недостаточно прав для изменения юридических документов.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    private function rejectUnexpected(array $data, array $allowed, string $path): void
    {
        foreach (array_diff(array_keys($data), $allowed) as $field) {
            throw ValidationException::withMessages(["{$path}.{$field}" => "Поле «{$field}» нельзя изменять."]);
        }
    }
}
