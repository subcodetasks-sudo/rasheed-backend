<?php

namespace App\Core\Traits;

use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Image\Enums\Fit;

trait HasStandardMedia
{
    use InteractsWithMedia;

    public function registerMediaConversions(Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 150, 150)
            ->nonQueued();

        $this->addMediaConversion('medium')
            ->width(600) 
            ->nonQueued();

        $this->addMediaConversion('large')
            ->width(1200) 
            ->nonQueued();

        $this->addMediaConversion('webp')
            ->format('webp')
            ->nonQueued();

        $this->addMediaConversion('optimized')
            ->optimize()
            ->quality(85)
            ->nonQueued();

        $this->addMediaConversion('avatar')
            ->fit(Fit::Crop, 300, 300)
            ->optimize()
            ->nonQueued();
    }
}