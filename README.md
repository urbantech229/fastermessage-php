# FasterMessage PHP

SDK PHP officiel de l'API FasterMessage v2 : SMS, e-mail, codes de vérification (OTP), contacts et webhooks.

PHP 8.1 ou plus récent, avec les extensions `curl` et `json`.

```bash
composer require fastermessage/fastermessage-php
```

## Démarrage

```php
use FasterMessage\Client;

$fm = new Client(
    getenv('FM_API_USER'),
    getenv('FM_API_PASSWORD'),
    getenv('FM_API_KEY'), // fm_test_… simule, fm_live_… envoie et débite
);

$message = $fm->sms->send([
    'to' => '+2290160000000',
    'from' => 'FASTERMSG',
    'content' => 'Votre commande est prete.',
    'client_reference' => 'cmd-42',
]);

echo $message['id'], ' ', $message['status']; // queued
```

Sur le domaine d'API de votre marque : `new Client($user, $password, $key, ['base_url' => 'https://api.votre-marque.com'])`. L'adresse doit être en `https` : `http` n'est accepté que vers la machine locale.

## Ce que fait le SDK

| Besoin | Appel |
|---|---|
| SMS | `$fm->sms->send()`, `$fm->sms->sendBatch()` (500 lignes) |
| E-mail | `$fm->email->send()` |
| WhatsApp | `$fm->whatsapp->send()` |
| Ce que vous pouvez utiliser (WhatsApp) | `$fm->whatsapp->listNumbers()`, `listTemplates()`, `allNumbers()`, `allTemplates()` |
| Ce que vous pouvez utiliser (e-mail) | `$fm->email->listSenders()`, `listTemplates()`, `allSenders()`, `allTemplates()` |
| Canal en paramètre | `$fm->messages->send()` |
| Suivi | `$fm->messages->get()`, `list()`, `all()` |
| OTP | `$fm->otp->request()` (SMS ou WhatsApp), `$fm->otp->verify()` |
| Contacts | `$fm->contacts`, `$fm->contactGroups` : `create`, `get`, `list`, `all`, `update`, `delete` |
| Compte | `$fm->account->balance()`, `credits()`, `allCredits()` |
| Repli | `fallback` sur `send()` et `$fm->otp->request()` |
| Webhooks | `Webhook::isGenuine()` |

Les réponses sont des tableaux associatifs, aux noms de champs de l'API : un champ ajouté par l'API est disponible sans mise à jour du SDK. La référence complète est dans la [documentation de l'API](https://www.fastermessage.com/docs).

## Ce que vous pouvez utiliser

Ces lectures ne rendent que ce qui est validé et utilisable sur votre compte, exactement ce que l'envoi accepte : un élément listé ne sera pas refusé à l'envoi.

```php
foreach ($fm->whatsapp->allNumbers() as $number) {
    echo $number['number'];                      // à mettre dans `from`
}
foreach ($fm->whatsapp->allTemplates() as $template) {
    echo $template['name'], ' ', $template['language'], ' ', implode(',', $template['variables']); // 1,2,header…
}
foreach ($fm->email->allSenders() as $sender) {
    echo $sender['address'];                     // à mettre dans `from` d'un e-mail
}
$page = $fm->email->listTemplates(['limit' => 20]); // modèles publiés : subject, html, from_email…
```

## WhatsApp

`from` est le numéro WhatsApp raccordé à votre compte : il est obligatoire. Deux contenus, selon la règle de Meta :

```php
// Un modèle approuvé, à tout moment. Variables : '1', '2'… pour {{1}}, {{2}}… du corps.
$fm->whatsapp->send([
    'to' => '+2290160000000',
    'from' => '+2290161000000',
    'content' => ['template' => 'confirmation_commande', 'language' => 'fr', 'variables' => ['1' => 'Awa', '2' => '1187']],
]);

// Un texte libre, seulement si le destinataire vous a écrit dans les 24 h (sinon 409 session_window_closed).
$fm->whatsapp->send(['to' => '+2290160000000', 'from' => '+2290161000000', 'content' => ['text' => 'Votre commande est prête.']]);
```

Un message lu reste `delivered` ; sa lecture se lit dans `read_at`. L'envoi programmé, le lot et les en-têtes média ne sont pas encore ouverts sur WhatsApp. La voix n'est pas encore servie par l'API v2.

## Repli entre canaux

`fallback` est un tableau ordonné d'étapes `channel`, `to`, `from`, `content`, essayées dans l'ordre si le message principal échoue. `channel` vaut `sms`, `email` ou `whatsapp` (la voix est acceptée mais répond « non disponible »). Vers l'e-mail, `content` est facultatif (sauf après un modèle WhatsApp) ; vers le SMS, il est obligatoire ; vers WhatsApp, c'est un modèle (`template`, `language`, `variables`) ou un texte (`text`), qui ne part que si la fenêtre de 24 h est ouverte. Accepté par `$fm->sms->send()`, `$fm->email->send()`, `$fm->whatsapp->send()`, `$fm->messages->send()` et `$fm->otp->request()`, refusé par `$fm->sms->sendBatch()`.

```php
$message = $fm->sms->send([
    'to' => '+2290160000000',
    'from' => 'FASTERMSG',
    'content' => 'Votre commande est prete.',
    'fallback' => [
        ['channel' => 'email', 'to' => 'client@exemple.com', 'from' => 'no-reply@votre-marque.com', 'content' => ['subject' => 'Votre commande']],
    ],
]);

echo $message['fallback']['state'] ?? 'aucun repli'; // watching
```

La réponse porte `fallback` (`null` sans repli) : `will_run`, `state` (`blocked`, `watching`, `triggered`, `not_needed`, `failed`, `expired`) et `steps`, une entrée par étape (`channel`, `will_run`, `reason`, `message_id`), avec son `reason` quand elle ne partira pas (`insufficient_balance`, `sender_not_allowed`…). Le message de repli porte `fallback_of`, l'identifiant du message d'origine.

Pour un OTP, un `content` propre doit contenir `{code}`, et `callback_url` reçoit le rappel de repli :

```php
$otp = $fm->otp->request([
    'to' => '+2290160000000',
    'fallback' => [
        ['channel' => 'email', 'to' => 'client@exemple.com', 'from' => 'no-reply@votre-marque.com', 'content' => ['text' => 'Votre code : {code}']],
    ],
    'callback_url' => 'https://votre-site.com/webhooks/fastermessage',
]);
```

Le code peut aussi partir par WhatsApp, par votre modèle d'authentification approuvé (`template` facultatif s'il est unique), `from` étant alors le numéro WhatsApp :

```php
$otp = $fm->otp->request(['to' => '+2290160000000', 'channel' => 'whatsapp', 'from' => '+2290161000000']);
```

Avec une clé `fm_test_`, `'test_outcome' => 'failed'` (ou `'delivered'`) simule l'issue du message principal pour voir le repli partir ; avec une clé réelle, c'est un `400`.

## Rejeux sans double envoi

Chaque écriture porte une clé `Idempotency-Key` générée. Le SDK rejoue seul, avec la même clé et deux fois au plus, une coupure réseau, un `5xx` (sauf `501`) et un `429` qui annonce `Retry-After` (60 secondes au plus : au-delà, il lève `RateLimitException` avec `retryAfter`).

Passez votre propre clé pour survivre à un redémarrage de votre programme :

```php
$fm->sms->send($params, ['idempotency_key' => 'commande-42-confirmation']);
```

## Erreurs

```php
use FasterMessage\Exception\ApiException;
use FasterMessage\Exception\ConnectionException;
use FasterMessage\Exception\InsufficientBalanceException;

try {
    $fm->sms->send($params);
} catch (InsufficientBalanceException $e) {
    // Rechargez le compte débité.
} catch (ApiException $e) {
    // Testez errorCode, stable, et non le message.
    error_log("{$e->httpStatus} {$e->errorCode} {$e->param} {$e->requestId}");
} catch (ConnectionException $e) {
    // L'appel a pu aboutir : rejouez avec $e->idempotencyKey, jamais avec une nouvelle clé.
}
```

Exceptions : `InvalidRequestException` (400), `AuthenticationException` (401), `InsufficientBalanceException` (402), `PermissionException` (403), `NotFoundException` (404), `ConflictException` (409), `RateLimitException` (429), `NotImplementedException` (501), `ServerException` (5xx).

Dans un lot, lisez toujours `errors` : un retour normal ne garantit pas que toutes les lignes soient parties.

## Webhooks

```php
use FasterMessage\Webhook;

$body = file_get_contents('php://input');

if (!Webhook::isGenuine($body, $_SERVER['HTTP_X_FASTERMESSAGE_SIGNATURE'] ?? null, getenv('FM_WEBHOOK_SECRET'))) {
    http_response_code(401);
    exit;
}

http_response_code(200); // Répondez sous 10 secondes, traitez ensuite.
$event = json_decode($body, true);

if (($event['event'] ?? null) === 'fallback') {
    if ($event['fallback']['state'] === 'triggered') {
        // Le repli est parti : $event['fallback']['steps'][…]['message_id'] donne les messages envoyés.
    } elseif ($event['fallback']['state'] === 'failed') {
        // Aucune étape n'a pu partir : $event['fallback']['steps'][…]['reason'] dit pourquoi.
    }
}
```

Générez le secret dans « Intégration & API ». Le rappel SMS a le format `uuid`, `level`, `status` ; le rappel e-mail `id`, `status`, `error`.

Le rappel de repli se reconnaît à `event` qui vaut `fallback` : `id` (le message principal, ou la demande OTP si `kind` vaut `otp`), `kind`, `channel`, `client_reference`, `fallback` et `event_at`. Il part quand le repli part (`state` `triggered`) ou ne peut pas partir (`state` `failed`), en POST, signé comme les autres, vers le `callback_url` de la demande, sinon vers l'adresse du compte. Jamais avec une clé de test, et rien pour un compte aux événements vidés.

## Tests

```bash
composer install
composer test
```

## Licence

MIT.
