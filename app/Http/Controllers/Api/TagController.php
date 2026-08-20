<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTagRequest;
use App\Http\Requests\UpdateTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TagController extends Controller
{
    public function index(string $tenant): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Tag::class);

        return TagResource::collection(Tag::paginate());
    }

    public function store(string $tenant, StoreTagRequest $request): JsonResponse
    {
        $this->authorize('create', Tag::class);

        $tag = Tag::create($request->validated());

        return (new TagResource($tag))->response()->setStatusCode(201);
    }

    public function show(string $tenant, Tag $tag): TagResource
    {
        $this->authorize('view', $tag);

        return new TagResource($tag);
    }

    public function update(string $tenant, UpdateTagRequest $request, Tag $tag): TagResource
    {
        $this->authorize('update', $tag);

        $tag->update($request->validated());

        return new TagResource($tag->fresh());
    }

    public function destroy(string $tenant, Tag $tag): JsonResponse
    {
        $this->authorize('delete', $tag);

        $tag->delete();

        return response()->json(null, 204);
    }
}
