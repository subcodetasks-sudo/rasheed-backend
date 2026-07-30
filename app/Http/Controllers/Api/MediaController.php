<?php

namespace App\Http\Controllers\Api;

use App\Core\Contracts\MediaServiceInterface;
use App\Core\Resources\MediaResource;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class MediaController extends Controller
{
    protected MediaServiceInterface $mediaService;

    public function __construct(MediaServiceInterface $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
            'model_type' => 'required|string',
            'model_id' => 'required|integer',
            'collection' => 'nullable|string',
        ]);

        $model = $request->model_type::findOrFail($request->model_id);
        $media = $this->mediaService->upload($model, $request->file('file'), $request->collection ?? 'default');

        return $this->successResponse(__('messages.media_uploaded'), new MediaResource($media));
    }

    public function show($id)
    {
        $media = $this->mediaService->find($id);
        return $this->successResponse(__('messages.media_fetched'), new MediaResource($media));
    }

    public function destroy($id)
    {
        $this->mediaService->delete($id);
        return $this->successResponse(__('messages.media_deleted'));
    }

    public function download($id)
    {
        $media = $this->mediaService->find($id);
        return response()->download($media->getPath(), $media->file_name);
    }
}