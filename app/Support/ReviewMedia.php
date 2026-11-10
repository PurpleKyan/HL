<?php

namespace App\Support;

class ReviewMedia
{
    /**
     * @param  array<int, mixed>|null  $photos
     * @return array<int, string>
     */
    public static function paths(?array $photos): array
    {
        return collect($photos ?? [])
            ->filter(fn ($photo) => is_string($photo))
            ->map(fn (string $photo) => parse_url($photo, PHP_URL_PATH))
            ->filter(function ($path): bool {
                if (! is_string($path)) {
                    return false;
                }

                $path = ltrim($path, '/');
                if (str_starts_with($path, 'storage/')) {
                    $path = substr($path, strlen('storage/'));
                }

                return str_starts_with($path, 'reviews/')
                    && ! str_contains($path, '\\')
                    && ! in_array('..', explode('/', $path), true);
            })
            ->map(function (string $path): string {
                $path = ltrim($path, '/');

                return str_starts_with($path, 'storage/')
                    ? substr($path, strlen('storage/'))
                    : $path;
            })
            ->unique()
            ->values()
            ->all();
    }
}
