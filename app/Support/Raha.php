<?php

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * Shared business configuration exported from the frontend mocks (database/seed-data/*.json):
 * permission catalog, system roles, workflow actions, document rules, form rules, seeds.
 * Usage: raha('workflow.actions'), raha('rbac.permissionCatalog').
 */
class Raha
{
    /** @var array<string, array> */
    private static array $files = [];

    public static function file(string $name): array
    {
        if (! isset(self::$files[$name])) {
            $path = database_path("seed-data/{$name}.json");
            self::$files[$name] = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
        }

        return self::$files[$name];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        [$file, $path] = array_pad(explode('.', $key, 2), 2, null);
        $data = self::file($file);

        return $path === null ? $data : Arr::get($data, $path, $default);
    }
}
