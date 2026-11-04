<?php

declare(strict_types=1);

namespace FasterMessage\Resource;

final class Otp extends Resource
{
    /**
     * Émet un code. Sans `channel`, le SMS est retenu, seul canal servi.
     *
     * @param array<string, mixed> $params `to`, et en option `channel`, `from`, `length`, `ttl_seconds`, `template`, `client_reference`
     * @param array{idempotency_key?: string, request_id?: string} $options
     *
     * @return array<string, mixed> conservez `id` ; le code n'est jamais renvoyé
     */
    public function request(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v2/otp', [], $params + ['channel' => 'sms'], $options) ?? [];
    }

    /**
     * Vérifie un code. Un code faux ne lève rien : décidez sur `status`, qui vaut
     * `approved`, `rejected` ou `expired`.
     *
     * @return array<string, mixed>
     */
    public function verify(string $id, string $code): array
    {
        return $this->http->request('POST', '/v2/otp/verify', [], ['id' => $id, 'code' => $code]) ?? [];
    }
}
