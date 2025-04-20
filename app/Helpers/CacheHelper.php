<?php

namespace App\Helpers;

use Stancl\Tenancy\Facades\Tenancy;

class CacheHelper
{
    public static function cacheInContext(string $key, $data = '__GET__')
    {
        if (tenancy()->initialized) {
            $store = cache()->store('database');
            return $data === '__GET__'
                ? $store->get($key)
                : ($data === null ? $store->forget($key) : $store->forever($key, $data));
        } else {
            return tenancy()->central(function () use ($key, $data) {
                $store = cache()->store('database');
                return $data === '__GET__'
                    ? $store->get($key)
                    : ($data === null ? $store->forget($key) : $store->forever($key, $data));
            });
        }
    }
}
