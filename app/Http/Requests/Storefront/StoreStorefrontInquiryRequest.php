<?php

namespace App\Http\Requests\Storefront;

use App\Enums\StorefrontInquiryType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStorefrontInquiryRequest extends FormRequest
{
    protected $errorBag = 'inquiry';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => trim((string) $this->input('phone'))]);
        }

        if ($this->query->has('product_context')) {
            $this->merge(['product_context' => $this->query('product_context')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = StorefrontInquiryType::tryFrom((string) $this->input('type'));
        $productConsultation = $type === StorefrontInquiryType::ProductConsultation;

        return [
            'type' => ['required', Rule::enum(StorefrontInquiryType::class)],
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:100',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    $phone = trim($value);
                    if ($phone === '' || preg_match('/^[0-9+()\-\x20\t\r\n\f\v]+$/', $phone) !== 1) {
                        $fail('Укажите корректный номер телефона.');

                        return;
                    }

                    $digits = preg_replace('/\D+/', '', $phone) ?? '';
                    $length = strlen($digits);
                    if ($length < 10 || $length > 15) {
                        $fail('Укажите корректный номер телефона.');
                    }
                },
            ],
            'email' => ['nullable', 'string', 'email:filter', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'product_variant_id' => [
                Rule::requiredIf($productConsultation),
                Rule::prohibitedIf(! $productConsultation),
                'integer',
                'min:1',
            ],
            'product_context' => [
                Rule::requiredIf($productConsultation),
                Rule::prohibitedIf(! $productConsultation),
                'integer',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail) use ($productConsultation): void {
                    if ($productConsultation && ! $this->hasValidSignature()) {
                        $fail('Контекст товара для заявки недействителен. Обновите страницу и попробуйте снова.');
                    }
                },
            ],
            'source_code' => ['required', 'string', Rule::in($type?->allowedSourceCodes() ?? []), 'max:100'],
            'company_website' => ['nullable', 'prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Поле «:attribute» обязательно.',
            'string' => 'Поле «:attribute» должно быть строкой.',
            'email' => 'Укажите корректный email.',
            'max' => 'Поле «:attribute» слишком длинное.',
            'type.enum' => 'Выбран неизвестный тип заявки.',
            'product_variant_id.required' => 'Выберите вариант товара для консультации.',
            'product_variant_id.prohibited' => 'Вариант товара недопустим для этого типа заявки.',
            'product_variant_id.integer' => 'Выбран некорректный вариант товара.',
            'product_variant_id.min' => 'Выбран некорректный вариант товара.',
            'product_context.required' => 'Контекст товара для заявки отсутствует. Обновите страницу и попробуйте снова.',
            'product_context.prohibited' => 'Контекст товара недопустим для этого типа заявки.',
            'product_context.integer' => 'Контекст товара для заявки недействителен.',
            'product_context.min' => 'Контекст товара для заявки недействителен.',
            'source_code.in' => 'Источник заявки не поддерживается.',
            'company_website.prohibited' => 'Заявка отклонена системой защиты от спама.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'type' => 'тип заявки',
            'name' => 'имя',
            'phone' => 'телефон',
            'email' => 'email',
            'message' => 'сообщение',
            'source_code' => 'источник заявки',
            'product_context' => 'контекст товара',
        ];
    }
}
