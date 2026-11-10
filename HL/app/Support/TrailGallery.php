<?php

namespace App\Support;

use App\Models\Trail;
use Illuminate\Support\Facades\Storage;

class TrailGallery
{
    public static function photos(Trail $trail): array
    {
        $disk = Storage::disk('public');
        $directory = "trail-gallery/{$trail->id}";
        $photos = [];
        foreach ($disk->files($directory) as $path) {
            $photos[] = [
                'id' => 'file-'.basename($path),
                'trail_id' => $trail->id,
                'url' => $disk->url($path),
                'created_at' => date(DATE_ATOM, $disk->lastModified($path)),
            ];
        }

        return $photos;
    }

    public static function attach(Trail $trail): Trail
    {
        $trail->setAttribute('photos', self::photos($trail));

        return $trail;
    }
}
