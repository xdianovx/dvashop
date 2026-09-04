<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

class BitrixWebhookClient
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @param  array{TITLE:string,NAME:string,PHONE:array<int, array{VALUE:string,VALUE_TYPE:string}>,EMAIL:array<int, array{VALUE:string,VALUE_TYPE:string}>,COMMENTS?:string,SOURCE_DESCRIPTION?:string,SOURCE_ID?:string,ASSIGNED_BY_ID?:string,OPPORTUNITY?:string,CURRENCY_ID?:string,IS_MANUAL_OPPORTUNITY?:string}  $fields
     */
    public function addLead(array $fields, string $method): string
    {
        $entityId = $this->request($method, ['fields' => $fields])->json('result');

        if (! is_int($entityId) && ! is_string($entityId)) {
            throw new InvalidArgumentException('Bitrix не вернул идентификатор созданной заявки.');
        }

        return (string) $entityId;
    }

    /**
     * @param  list<array{PRODUCT_NAME:string,PRICE:string,QUANTITY:int}>  $rows
     */
    public function setLeadProductRows(string|int $leadId, array $rows): void
    {
        $response = $this->request('crm.lead.productrows.set', ['id' => $leadId, 'rows' => $rows]);

        if ($response->json('result') !== true) {
            throw new InvalidArgumentException('Bitrix не подтвердил сохранение товаров заказа.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function request(string $method, array $payload): Response
    {
        $webhookUrl = trim((string) config('shop.bitrix.webhook_url'));

        if ($webhookUrl === '') {
            throw new InvalidArgumentException('Webhook Bitrix не настроен.');
        }

        if (! preg_match('/^[a-z][a-z0-9_.]+$/i', $method)) {
            throw new InvalidArgumentException('Метод Bitrix настроен некорректно.');
        }

        return $this->http
            ->asForm()
            ->timeout(10)
            ->post(rtrim($webhookUrl, '/').'/'.$method.'.json', $payload)
            ->throw();
    }
}
