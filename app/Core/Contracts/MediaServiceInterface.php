<?php

namespace App\Core\Contracts;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

interface MediaServiceInterface
{
    public function upload(HasMedia $model, $file, string $collection): Media;
    public function find(int $id): Media;
    public function delete(int $id): bool;
    public function download(int $id);
}