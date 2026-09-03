<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;

class BitrixWebhookClient
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @param  array{TITLE:string,NAME:string,PHONE:array<int, array{VALUE:string,VALUE_TYPE:string}>,EMAIL:array<int, array{VALUE:string,VALUE_TYPE:string}>,COMMENTS:string,SOURCE_ID?:string,ASSIGNED_BY_ID?:string}  $fields
     */
    public function addLead(array $fields, string $method): string
    {
        $webhookUrl = trim((string) config('shop.bitrix.webhook_url'));

        if ($webhookUrl === '') {
            throw new InvalidArgumentException('Webhook Bitrix не настроен.');
        }

        if (! preg_match('/^[a-z][a-z0-9_.]+$/i', $method)) {
            throw new InvalidArgumentException('Метод Bitrix настроен некорректно.');
        }

        $response = $this->http
            ->asForm()
            ->timeout(10)
            ->post(rtrim($webhookUrl, '/').'/'.$method.'.json', ['fields' => $fields])
            ->throw();

        $entityId = $response->json('result');

        if (! is_int($entityId) && ! is_string($entityId)) {
            throw new InvalidArgumentException('Bitrix не вернул идентификатор созданной заявки.');
        }

        return (string) $entityId;
    }
}
