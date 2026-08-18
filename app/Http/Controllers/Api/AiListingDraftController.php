<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\ListingDraftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

class AiListingDraftController extends Controller
{
    public function __construct(protected ListingDraftService $drafts) {}

    public function store(Request $request)
    {
        // Qwen2-VL on MPS often needs 30–120s; PHP web SAPI default (30s) kills Guzzle mid-request.
        $aiTimeout = max(60, (int) config('rebox.ai.timeout', 120));
        @set_time_limit($aiTimeout + 30);
        @ini_set('max_execution_time', (string) ($aiTimeout + 30));

        $user = $request->attributes->get('authUser');
        $key = 'ai-listing-draft:'.($user?->id ?? $request->ip());
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json([
                'message' => 'Too many AI draft requests. Please wait a minute.',
            ], 429);
        }
        RateLimiter::hit($key, 60);

        $images = $request->input('images', []);
        if (! is_array($images)) {
            return response()->json(['message' => 'images must be an array of URLs.'], 400);
        }
        $images = array_values(array_filter(array_map('strval', $images)));
        if ($images === []) {
            return response()->json(['message' => 'Upload 1–4 product photos first.'], 400);
        }
        if (count($images) > 4) {
            return response()->json(['message' => 'At most 4 images are allowed.'], 400);
        }

        $categorySlug = $request->input('categorySlug', $request->input('category_slug'));
        $categorySlug = is_string($categorySlug) && $categorySlug !== '' ? $categorySlug : null;

        try {
            $draft = $this->drafts->draft($images, $categorySlug);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json([
            'message' => 'AI listing draft ready. Review and edit before submitting.',
            'draft' => $draft,
            'aiMeta' => [
                'draft' => $draft,
                'risk_score' => $draft['riskScore'],
                'flags' => $draft['flags'],
                'notes_for_admin' => $draft['notesForAdmin'],
                'model' => $draft['modelVersion'],
                'checked_at' => now()->toISOString(),
                'image_urls' => $images,
            ],
        ]);
    }
}
