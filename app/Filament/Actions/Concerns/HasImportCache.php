<?php

namespace App\Filament\Actions\Concerns;

/**
 * Provides session-based file caching for multi-step import wizards.
 * Each class using this trait must define a static CACHE_PREFIX constant.
 */
trait HasImportCache
{
    protected static array $memoryCache = [];

    protected static function tempPath(string $suffix): string
    {
        return storage_path('app/private/' . static::CACHE_PREFIX . '-' . session()->getId() . '-' . $suffix . '.json');
    }

    protected static function putCache(string $suffix, mixed $data): void
    {
        $path = self::tempPath($suffix);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    protected static function getCache(string $suffix, mixed $default = null): mixed
    {
        if (isset(self::$memoryCache[$suffix])) {
            return self::$memoryCache[$suffix];
        }

        $path = self::tempPath($suffix);
        if (! file_exists($path)) {
            return $default;
        }

        $data = json_decode(file_get_contents($path), true) ?? $default;
        self::$memoryCache[$suffix] = $data;

        return $data;
    }

    protected static function forgetCacheKeys(array $keys): void
    {
        foreach ($keys as $key) {
            @unlink(self::tempPath($key));
        }
        self::$memoryCache = [];
    }
}
