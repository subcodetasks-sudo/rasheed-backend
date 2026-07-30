<?php


namespace App\Core\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    public function toArray($request): array
    {
        $isImage = str_starts_with($this->mime_type, 'image');
        return [
            'id' => $this->id,
            'fileName' => $this->file_name,
            'mimeType' => $this->mime_type,
            'size' => $this->human_readable_size,
            'urls' => $isImage ? [
                'original' => $this->getFullUrl(),
                'thumb' => $this->getFullUrl('thumb'),
                'medium' => $this->getFullUrl('medium'),
                'large' => $this->getFullUrl('large'),
                'webp' => $this->getFullUrl('webp'),
            ] : [
                    'original' => $this->getFullUrl(),
            ],
            'createdAt' => $this->created_at
        ];
    }
}