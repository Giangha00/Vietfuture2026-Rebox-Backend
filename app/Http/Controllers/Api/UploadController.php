<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    public function store(Request $request)
    {
        $files = $this->collectImageFiles($request);

        if ($files === []) {
            return response()->json(['message' => 'No images uploaded.'], 400);
        }
        if (count($files) > 8) {
            return response()->json(['message' => 'You can upload at most 8 images.'], 400);
        }

        $urls = [];
        $base = rtrim((string) config('rebox.public_base_url'), '/');

        foreach ($files as $file) {
            if (! str_starts_with((string) $file->getMimeType(), 'image/')) {
                return response()->json(['message' => 'Only image files are allowed.'], 400);
            }
            if ($file->getSize() > (int) (1.5 * 1024 * 1024)) {
                return response()->json(['message' => 'Each image must be 1.5MB or smaller.'], 400);
            }

            $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg';
            $name = Str::uuid()->toString().'.'.$extension;
            $path = $file->storeAs('uploads', $name, 'public');
            $urls[] = $base.'/storage/'.$path;
        }

        return response()->json([
            'message' => 'Upload successful.',
            'urls' => $urls,
            'count' => count($urls),
        ], 201);
    }

    /**
     * @return list<UploadedFile>
     */
    protected function collectImageFiles(Request $request): array
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
}
