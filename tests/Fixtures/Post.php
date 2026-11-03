<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Mozex\Iminify\Laravel\IminifyOptimizer;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Post extends Model implements HasMedia
{
    use InteractsWithMedia;

    public static bool $queued = false;

    public static bool $ownChain = false;

    public function registerMediaConversions(?Media $media = null): void
    {
        $conversion = $this->addMediaConversion('thumb')->width(40);

        static::$queued ? $conversion->queued() : $conversion->nonQueued();

        if (static::$ownChain) {
            $conversion->optimize(IminifyOptimizer::chain());
        }
    }
}
