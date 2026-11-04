<?php

declare(strict_types=1);

namespace FasterMessage\Resource;

final class Messages extends Resource
{
    /**
     * Envoi avec le canal en paramètre : `channel` vaut `sms` ou `email`.
     *
     * @param array<string, mixed> $params
     * @param array{idempotency_key?: string, request_id?: string} $options
     *
     * @return array<string, mixed>
     */
    public function send(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v2/messages', [], $params, $options) ?? [];
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/v2/messages/' . self::segment($id)) ?? [];
    }

    /**
     * Une page. Attention : sans `channel`, l'API ne rend que les SMS.
     *
     * @param array<string, mixed> $query `channel`, `status`, `client_reference`, `limit`, `cursor`
     *
     * @return array<string, mixed> `data`, `has_more`, `next_cursor`
     */
    public function list(array $query = []): array
    {
        return $this->http->request('GET', '/v2/messages', $query) ?? [];
    }

    /**
     * Tous les messages, page après page.
     *
     * @param array<string, mixed> $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        return $this->paginate('/v2/messages', $query);
    }
}
