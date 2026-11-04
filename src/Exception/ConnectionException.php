<?php

declare(strict_types=1);

namespace FasterMessage\Exception;

/**
 * Aucune réponse obtenue, reprises comprises. L'appel a pu aboutir côté serveur :
 * rejouez-le avec la même clé d'idempotence, jamais avec une nouvelle.
 */
class ConnectionException extends FasterMessageException
{
    public ?string $idempotencyKey = null;
}
