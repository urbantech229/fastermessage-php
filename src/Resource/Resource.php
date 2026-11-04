<?php

declare(strict_types=1);

namespace FasterMessage\Resource;

use FasterMessage\Http\Requester;

/** @internal */
abstract class Resource
{
    public function __construct(protected readonly Requester $http)
    {
    }

    /**
     * Parcourt toutes les pages d'une liste, en suivant le curseur.
     *
     * @param array<string, mixed> $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    protected function paginate(string $path, array $query): \Generator
    {
        do {
            $page = $this->http->request('GET', $path, $query) ?? [];
            foreach ($page['data'] ?? [] as $row) {
                yield $row;
            }
            $query['cursor'] = $page['next_cursor'] ?? null;
        } while (($page['has_more'] ?? false) === true && $query['cursor'] !== null);
    }

    protected static function segment(string $id): string
    {
        return rawurlencode($id);
    }
}
