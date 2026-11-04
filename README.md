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

Sur le domaine d'API de votre marque : `new Client($user, $password, $key, ['base_url' => 'https://api.votre-marque.com'])`.

## Ce que fait le SDK

| Besoin | Appel |
|---|---|
| SMS | `$fm->sms->send()`, `$fm->sms->sendBatch()` (500 lignes) |
| E-mail | `$fm->email->send()` |
| Canal en paramètre | `$fm->messages->send()` |
| Suivi | `$fm->messages->get()`, `list()`, `all()` |
| OTP | `$fm->otp->request()`, `$fm->otp->verify()` |
| Contacts | `$fm->contacts`, `$fm->contactGroups` : `create`, `get`, `list`, `all`, `update`, `delete` |
| Compte | `$fm->account->balance()`, `credits()`, `allCredits()` |
| Webhooks | `Webhook::isGenuine()` |

Les réponses sont des tableaux associatifs, aux noms de champs de l'API : un champ ajouté par l'API est disponible sans mise à jour du SDK. La référence complète est dans la [documentation de l'API](https://www.fastermessage.com/docs).

WhatsApp et la voix ne sont pas encore servis par l'API v2.

## Rejeux sans double envoi

Chaque écriture porte une clé `Idempotency-Key` générée. Le SDK rejoue seul, avec la même clé et deux fois au plus, une coupure réseau, un `5xx` (sauf `501`) et un `429` qui annonce `Retry-After`.

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
```

Générez le secret dans « Intégration & API ». Le rappel SMS a le format `uuid`, `level`, `status` ; le rappel e-mail `id`, `status`, `error`.

## Tests

```bash
composer install
composer test
```

## Licence

MIT.
