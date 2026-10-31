<?php

namespace App\Http\Controllers\Api;

use App\Models\Upload;
use App\Services\Access;
use App\Services\Permissions;
use App\Services\Uploads;
use App\Support\ApiError;
use App\Support\Ctx;
use Illuminate\Http\Request;

/** Uploading and serving photos. */
class MediaController extends ApiController
{
    /** Shop staff upload into their store (form field storeId); Topline admins may upload site images. */
    public function upload(Request $request)
    {
        $user = Access::currentUser();
        $storeId = $request->input('storeId') ?: null;
        if ($storeId) {
            Access::loadStore($storeId);
            Ctx::require(Permissions::MANAGE_PRODUCTS);
        } elseif (! $user->isPlatformAdmin()) {
            throw ApiError::notFound("That store doesn't exist.");
        }

        return $this->json(Uploads::save($request->file('file'), $user, $storeId), 201);
    }

    public function show(string $name)
    {
        if (str_contains($name, '/') || str_contains($name, '..')) {
            throw ApiError::notFound('Photo not found.');
        }
        // Names are random and never reused, so browsers may keep them for a year.
        $headers = ['Cache-Control' => 'public, max-age=31536000, immutable'];
        $path = Uploads::folder().'/'.$name;
        if (is_file($path)) {
            return response()->file($path, $headers);
        }
        $row = Upload::find($name);
        if ($row && $row->data !== null) {
            return response($row->data, 200, [...$headers, 'Content-Type' => $row->content_type]);
        }
        throw ApiError::notFound('Photo not found.');
    }
}
