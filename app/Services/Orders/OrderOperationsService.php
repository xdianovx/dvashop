<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderOperationsService
{
    public function __construct(private readonly OrderInventoryService $inventory) {}

    /** @var list<string> */
    private const EDITABLE_FIELDS = [
        'status',
        'payment_status',
        'manager_comment',
    ];

    /** @param array<string, mixed> $data */
    public function update(User $actor, Order $order, array $data): Order
    {
        if (! $actor->can('update', $order)) {
            throw new AuthorizationException('Недостаточно прав для изменения заказа.');
        }

        $unexpected = array_values(array_diff(array_keys($data), self::EDITABLE_FIELDS));

        if ($unexpected !== []) {
            throw ValidationException::withMessages(collect($unexpected)
                ->mapWithKeys(fn (string $field): array => [$field => "Поле «{$field}» нельзя изменять после оформления заказа."])
                ->all());
        }

        return DB::transaction(function () use ($order, $data): Order {
            $locked = Order::query()->whereKey($order)->lockForUpdate()->firstOrFail();
            $candidate = array_merge($locked->only(self::EDITABLE_FIELDS), $data);

            if (($candidate['status'] ?? null) instanceof OrderStatus) {
                $candidate['status'] = $candidate['status']->value;
            }

            if (($candidate['payment_status'] ?? null) instanceof PaymentStatus) {
                $candidate['payment_status'] = $candidate['payment_status']->value;
            }

            if (is_string($candidate['manager_comment'] ?? null)) {
                $candidate['manager_comment'] = trim($candidate['manager_comment']);
            }

            if (($candidate['manager_comment'] ?? null) === '') {
                $candidate['manager_comment'] = null;
            }

            $validated = Validator::make($candidate, [
                'status' => ['required', Rule::enum(OrderStatus::class)],
                'payment_status' => ['required', Rule::enum(PaymentStatus::class)],
                'manager_comment' => ['nullable', 'string', 'max:5000'],
            ], [
                'status.required' => 'Укажите статус заказа.',
                'status.enum' => 'Выбран неизвестный статус заказа.',
                'payment_status.required' => 'Укажите статус оплаты.',
                'payment_status.enum' => 'Выбран неизвестный статус оплаты.',
                'manager_comment.string' => 'Комментарий менеджера должен быть строкой.',
                'manager_comment.max' => 'Комментарий менеджера не может быть длиннее 5000 символов.',
            ])->validate();

            $targetStatus = OrderStatus::from($validated['status']);
            $targetPaymentStatus = PaymentStatus::from($validated['payment_status']);

            if (! $locked->status->canTransitionTo($targetStatus)) {
                throw ValidationException::withMessages([
                    'status' => "Переход статуса заказа «{$locked->status->label()}» → «{$targetStatus->label()}» запрещён.",
                ]);
            }

            if (! $locked->payment_status->canTransitionTo($targetPaymentStatus)) {
                throw ValidationException::withMessages([
                    'payment_status' => "Переход статуса оплаты «{$locked->payment_status->label()}» → «{$targetPaymentStatus->label()}» запрещён.",
                ]);
            }

            $firstPaidTransition = $locked->payment_status !== PaymentStatus::Paid
                && $targetPaymentStatus === PaymentStatus::Paid
                && $locked->paid_at === null;
            $firstCancellation = $locked->status !== OrderStatus::Canceled
                && $targetStatus === OrderStatus::Canceled;

            if ($firstCancellation) {
                $this->inventory->restoreForCancellation($locked);
                $locked->promoCodeRedemption()
                    ->whereNull('released_at')
                    ->update(['released_at' => now()]);
            }

            $locked->forceFill([
                'status' => $targetStatus,
                'payment_status' => $targetPaymentStatus,
                'manager_comment' => $validated['manager_comment'] ?? null,
            ]);

            if ($firstPaidTransition) {
                $locked->paid_at = now();
            }

            if ($locked->isDirty()) {
                $locked->save();
            }

            return $locked->refresh();
        });
    }
}
