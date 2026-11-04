<?php

declare(strict_types=1);

namespace FasterMessage\Resource;

final class Sms extends Resource
{
    /**
     * Envoie un SMS. Champs : `to`, `from`, `content`, et en option `scheduled_at`,
     * `callback_url`, `client_reference`.
     *
     * @param array<string, mixed> $params
     * @param array{idempotency_key?: string, request_id?: string} $options
     *
     * @return array<string, mixed> la ressource message
     */
    public function send(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v2/sms/send', [], $params, $options) ?? [];
    }

    /**
     * Envoie jusqu'à 500 SMS. Lisez toujours `errors` dans le bilan : un retour normal
     * ne garantit pas que toutes les lignes soient parties.
     *
     * @param array<string, mixed> $params `messages`, et en option `from`, `content`, `scheduled_at`, `callback_url`
     * @param array{idempotency_key?: string, request_id?: string} $options
     *
     * @return array<string, mixed> le bilan : `total`, `accepted`, `rejected`, `data`, `errors`
     */
    public function sendBatch(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v2/sms/batch', [], $params, $options) ?? [];
    }
}
