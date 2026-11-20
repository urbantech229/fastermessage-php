<?php

declare(strict_types=1);

namespace FasterMessage\Resource;

final class Whatsapp extends Resource
{
    /**
     * Envoie un message WhatsApp. Champs : `to`, `from` (numéro WhatsApp raccordé au compte,
     * obligatoire) et `content`, l'un ou l'autre :
     *
     * - un modèle approuvé, à tout moment : `['template' => 'nom', 'language' => 'fr', 'variables' => [...]]`.
     *   Variables : `'1'`, `'2'`… pour `{{1}}`, `{{2}}`… du corps, `'header'` pour l'en-tête texte,
     *   `'button_1'`… pour le lien d'un bouton. `language` est facultatif si le modèle n'existe qu'en une langue ;
     * - un texte libre, seulement si le destinataire a écrit à ce numéro dans les 24 h :
     *   `['text' => '...']` (sinon `409 session_window_closed`).
     *
     * En option : `fallback` (étapes vers le SMS ou l'e-mail ; après un modèle, une étape e-mail porte
     * son contenu), `test_outcome`, `callback_url`, `client_reference`. L'envoi programmé n'est pas
     * encore ouvert sur WhatsApp. Un message lu reste `delivered` ; sa lecture se lit dans `read_at`.
     *
     * @param array<string, mixed> $params
     * @param array{idempotency_key?: string, request_id?: string} $options
     *
     * @return array<string, mixed> la ressource message
     */
    public function send(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v2/whatsapp/send', [], $params, $options) ?? [];
    }
}
