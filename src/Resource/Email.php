<?php

declare(strict_types=1);

namespace FasterMessage\Resource;

final class Email extends Resource
{
    /**
     * Envoie un e-mail à une adresse. Champs : `to`, `content` (`subject`, et `html`
     * et/ou `text`), et en option `from`, `fallback`, `test_outcome`, `scheduled_at`, `callback_url`,
     * `client_reference`.
     *
     * `fallback` est un tableau ordonné d'étapes `['channel' => 'sms'|'whatsapp', 'to', 'from', 'content']` :
     * `content` est obligatoire vers le SMS (chaîne ou `['text', 'unicode']`), et vers WhatsApp un modèle
     * (`template`, `language`, `variables`) ou un texte (`text`, dans la fenêtre de 24 h).
     * La réponse porte alors `fallback` (`will_run`, `state`, `steps`), `null` sans repli.
     * `test_outcome` (`failed` ou `delivered`) simule l'issue, avec une clé `fm_test_` seulement.
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
