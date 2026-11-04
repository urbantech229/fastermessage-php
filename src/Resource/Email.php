<?php

declare(strict_types=1);

namespace FasterMessage\Resource;

final class Email extends Resource
{
    /**
     * Envoie un e-mail à une adresse. Champs : `to`, `content` (`subject`, et `html`
     * et/ou `text`), et en option `from`, `scheduled_at`, `callback_url`, `client_reference`.
     *
     * @param array<string, mixed> $params
     * @param array{idempotency_key?: string, request_id?: string} $options
     *
     * @return array<string, mixed> la ressource message
     */
    public function send(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v2/email/send', [], $params, $options) ?? [];
    }
}
