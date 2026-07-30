<?php

namespace App\Core\Services;

use App\Core\Contracts\MediaServiceInterface;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaService implements MediaServiceInterface
{
    public function upload(HasMedia $model, $file, string $collection = 'default'): Media
    {
        return $model->addMedia($file)->toMediaCollection($collection);
    }

    public function find(int $id): Media
    {
        return Media::findOrFail($id);
    }

    public function delete(int $id): bool
    {
        return Media::findOrFail($id)->delete();
    }

    public function download(int $id)
    {
        $media = Media::findOrFail($id);
        return response()->download($media->getPath(), $media->file_name);
    }
}