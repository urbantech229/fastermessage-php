<?php

declare(strict_types=1);

namespace FasterMessage\Resource;

final class Otp extends Resource
{
    /**
     * Émet un code. Sans `channel`, le SMS est retenu, seul canal servi.
     *
     * Le repli (`fallback`, vers l'e-mail) reprend la forme des envois ; un `content` propre doit
     * contenir `{code}`. `callback_url` reçoit le rappel de repli, à défaut l'adresse du compte.
     * `test_outcome` (`failed` ou `delivered`) simule l'issue, avec une clé `fm_test_` seulement.
     *
     * @param array<string, mixed> $params `to`, et en option `channel`, `from`, `length`, `ttl_seconds`, `template`,
     *                                     `client_reference`, `fallback`, `test_outcome`, `callback_url`
     * @param array{idempotency_key?: string, request_id?: string} $options
     *
     * @return array<string, mixed> conservez `id` ; le code n'est jamais renvoyé. `fallback` vaut `null` sans repli
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
