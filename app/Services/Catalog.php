<?php

namespace App\Services;

/** Product categories shared by every store. `image` is a picture key the website knows. */
class Catalog
{
    public const CATEGORIES = [
        ['id' => 'cement', 'name' => 'Cement & binding', 'image' => 'cement'],
        ['id' => 'steel', 'name' => 'Steel & rebar', 'image' => 'rebar'],
        ['id' => 'roofing', 'name' => 'Roofing', 'image' => 'roofing'],
        ['id' => 'timber', 'name' => 'Timber', 'image' => 'timber'],
        ['id' => 'plumbing', 'name' => 'Plumbing', 'image' => 'pipes'],
        ['id' => 'paint', 'name' => 'Paint', 'image' => 'paint'],
        ['id' => 'electrical', 'name' => 'Electrical', 'image' => 'wires'],
        ['id' => 'tools', 'name' => 'Tools', 'image' => 'hammer'],
    ];

    public static function ids(): array
    {
        return array_column(self::CATEGORIES, 'id');
    }

    public static function find(string $id): ?array
    {
        foreach (self::CATEGORIES as $category) {
            if ($category['id'] === $id) {
                return $category;
            }
        }

        return null;
    }
}
