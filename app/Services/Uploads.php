<?php

namespace App\Services;

use App\Models\Upload;
use App\Models\User;
use App\Support\ApiError;
use Illuminate\Http\UploadedFile;

/**
 * Photo uploads (product pictures, shop logos/covers, Topline site images).
 * Files are checked by their actual bytes (not the name), limited in size and saved under a
 * random name in storage/app/uploads (or in the database with UPLOAD_STORAGE=database).
 * The URL is /api/uploads/<id> either way. The website shrinks photos before uploading.
 */
class Uploads
{
    public static function folder(): string
    {
        return config('topline.upload_folder') ?: storage_path('app/uploads');
    }

    /** [extension, content type] from the first bytes, or null if not an allowed image. */
    public static function detect(string $head): ?array
    {
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return ['jpg', 'image/jpeg'];
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return ['png', 'image/png'];
        }
        if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return ['webp', 'image/webp'];
        }

        return null;
    }

    public static function save(?UploadedFile $file, User $user, ?string $storeId = null): array
    {
        if (! $file || ! $file->isValid()) {
            throw ApiError::invalid('Choose a photo to upload.', 'file');
        }
        $data = (string) file_get_contents($file->getRealPath());
        $maxMb = (int) config('topline.max_upload_mb');
        if (strlen($data) > $maxMb * 1024 * 1024) {
            throw ApiError::invalid("That photo is too big. Use one under {$maxMb} MB.", 'file');
        }
        $kind = self::detect(substr($data, 0, 16));
        if (! $kind) {
            throw ApiError::invalid('Upload a JPG, PNG or WebP photo.', 'file');
        }
        [$ext, $contentType] = $kind;
        $id = bin2hex(random_bytes(12)).'.'.$ext;
        $inDatabase = config('topline.upload_storage') === 'database';
        if (! $inDatabase) {
            if (! is_dir(self::folder())) {
                mkdir(self::folder(), 0755, true);
            }
            file_put_contents(self::folder().'/'.$id, $data);
        }
        Upload::create(['id' => $id, 'store_id' => $storeId, 'user_id' => $user->id, 'content_type' => $contentType,
            'size' => strlen($data), 'data' => $inDatabase ? $data : null]);

        return ['id' => $id, 'url' => self::url($id)];
    }

    public static function url(string $id): string
    {
        return rtrim(request()->getSchemeAndHttpHost(), '/').'/api/uploads/'.$id;
    }
}
