<?php

namespace App\Services\Reset;

use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Session\CacheBasedSessionHandler;

/**
 * Apaga as sessões de login: o `Cache::flush()` não as alcança (ficam no Redis DB 0, o cache em outro), e depois do
 * `migrate:fresh` os IDs de usuário recomeçam, então uma sessão velha entraria como a conta nova de mesmo ID.
 */
class SessionPurger
{
    /** @return int quantas sessões foram apagadas */
    public function purge(): int
    {
        $handler = app('session.store')->getHandler();
        $store = $handler instanceof CacheBasedSessionHandler ? $handler->getCache()->getStore() : null;

        // shortcut: só o store Redis é varrido (o de produção); `file` não é limpo, nem vale para vários containers. Revisar se o driver mudar.
        if (! $store instanceof RedisStore) {
            return 0;
        }

        $redis = $store->connection();

        return $redis instanceof PhpRedisConnection || $redis instanceof PredisConnection ? $this->purgeRedis($redis, $store->getPrefix()) : 0;
    }

    /**
     * SCAN (nunca KEYS) e só as chaves `<prefixo do store><ID de 40 caracteres>`: locks e filas dividem o mesmo DB e ficam.
     * O phpredis devolve as chaves com o prefixo do cliente e o `del` o põe de novo, então ele sai antes.
     */
    public function purgeRedis(PhpRedisConnection|PredisConnection $redis, string $prefix): int
    {
        $clientPrefix = config()->string('database.redis.options.prefix', '');
        $pattern = '/^'.preg_quote($prefix, '/').'[A-Za-z0-9]{40}$/';
        $count = 0;
        $cursor = 0;

        do {
            $page = $redis->scan($cursor, ['match' => '*', 'count' => 1000]);

            // `false` é a primeira página vazia: nada a varrer.
            if (! is_array($page)) {
                break;
            }

            [$cursor, $keys] = $page;

            foreach (is_array($keys) ? $keys : [] as $key) {
                $session = $this->sessionKey($key, $clientPrefix, $pattern);
                $session === null || $redis->del($session);
                $count += $session === null ? 0 : 1;
            }
        } while (is_scalar($cursor) && (string) $cursor !== '0');

        return $count;
    }

    /** A chave sem o prefixo do cliente se for de sessão, ou `null` (lock, fila, outro). */
    private function sessionKey(mixed $key, string $clientPrefix, string $pattern): ?string
    {
        if (! is_string($key)) {
            return null;
        }

        $key = $clientPrefix !== '' && str_starts_with($key, $clientPrefix) ? substr($key, strlen($clientPrefix)) : $key;

        return preg_match($pattern, $key) === 1 ? $key : null;
    }
}
