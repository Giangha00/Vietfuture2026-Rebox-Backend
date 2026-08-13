<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UploadService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class UploadController extends Controller
{
    public function __construct(
        protected UploadService $uploads,
    ) {}

    public function store(Request $request)
    {
        try {
            $urls = $this->uploads->uploadFromRequest($request);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json([
            'message' => 'Upload successful.',
            'urls' => $urls,
            'count' => count($urls),
        ], 201);
    }
}
