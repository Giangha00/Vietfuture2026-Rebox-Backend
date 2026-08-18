<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

class UploadService
{
    /**
     * @return list<UploadedFile>
     */
    public function collectImageFiles(Request $request): array
    {
        $candidates = [];

        foreach (['images', 'images[]', 'files', 'file'] as $key) {
            $value = $request->file($key);
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                $candidates = array_merge($candidates, $value);
            } else {
                $candidates[] = $value;
            }
        }

        // Fallback: any uploaded file under the request.
        if ($candidates === []) {
            foreach ($request->allFiles() as $value) {
                if (is_array($value)) {
                    $candidates = array_merge($candidates, $value);
                } else {
                    $candidates[] = $value;
                }
            }
        }

        return array_values(array_filter(
            $candidates,
            fn ($file) => $file instanceof UploadedFile && $file->isValid()
        ));
    }

    /**
     * Validate, store uploaded images, and return public URLs.
     *
     * @param  list<UploadedFile>  $files
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public function store(array $files): array
    {
        $maxFiles = (int) config('rebox.upload.max_files', 8);
        $maxBytes = (int) config('rebox.upload.max_bytes', (int) (1.5 * 1024 * 1024));

        if ($files === []) {
            throw new InvalidArgumentException('No images uploaded.');
        }
        if (count($files) > $maxFiles) {
            throw new InvalidArgumentException("You can upload at most {$maxFiles} images.");
        }

        $urls = [];
        $base = rtrim((string) config('rebox.public_base_url'), '/');

        foreach ($files as $file) {
            if (! str_starts_with((string) $file->getMimeType(), 'image/')) {
                throw new InvalidArgumentException('Only image files are allowed.');
            }
            if ($file->getSize() > $maxBytes) {
                throw new InvalidArgumentException('Each image must be 1.5MB or smaller.');
            }

            $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg';
            $name = Str::uuid()->toString().'.'.$extension;
            $path = $file->storeAs('uploads', $name, 'public');
            $urls[] = $base.'/storage/'.$path;
        }

        return $urls;
    }

    /**
     * Collect files from the request, validate, store, and return URLs.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public function uploadFromRequest(Request $request): array
    {
        return $this->store($this->collectImageFiles($request));
    }
}
