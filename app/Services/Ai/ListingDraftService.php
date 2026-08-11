<?php

namespace App\Services\Ai;

use App\Models\Category;
use App\Support\CategorySchemas;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ListingDraftService
{
    /**
     * @param  list<string>  $images
     * @return array<string, mixed>
     */
    public function draft(array $images, ?string $categorySlug = null): array
    {
        $images = array_values(array_filter(array_map('strval', $images)));
        if ($images === []) {
            throw new RuntimeException('At least one image URL is required.');
        }
        if (count($images) > 4) {
            throw new RuntimeException('At most 4 images are allowed.');
        }

        $slug = $categorySlug ? strtolower(trim($categorySlug)) : null;
        $schema = $slug ? CategorySchemas::forSlug($slug) : null;

        $categories = Category::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
            ])
            ->values()
            ->all();

        $base = rtrim((string) config('rebox.ai.url'), '/');
        $timeout = (int) config('rebox.ai.timeout', 120);

        // Prefer local disk paths so rebox-ai never HTTP-fetches back into
        // php artisan serve (single-threaded → deadlock / max execution time).
        $imageRefs = array_map(fn (string $url) => $this->toLocalImageRef($url), $images);

        $payload = [
            'images' => $imageRefs,
            'category_slug' => $slug,
            'category_schema' => $schema,
            // Always send full schemas so AI can auto-detect category without a hint.
            'category_schemas' => CategorySchemas::all(),
            'categories' => $categories,
        ];

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->post($base.'/v1/listing-draft', $payload);
        } catch (\Throwable $e) {
            Log::warning('rebox-ai draft request failed', ['error' => $e->getMessage()]);
            throw new RuntimeException(
                'AI service unreachable. Start rebox-ai on '.($base ?: 'REBOX_AI_URL').'.'
            );
        }

        if (! $response->successful()) {
            $detail = $response->json('detail') ?? $response->json('message') ?? $response->body();
            throw new RuntimeException(is_string($detail) ? $detail : 'AI draft failed.');
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('AI draft returned invalid JSON.');
        }

        return $this->normalize($data, $categories);
    }

    /**
     * Map a public upload URL to a local filesystem path when possible.
     */
    protected function toLocalImageRef(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return $url;
        }

        // http://localhost:5001/storage/uploads/xxx.jpg → storage/app/public/uploads/xxx.jpg
        if (preg_match('#/storage/(.+)$#', $path, $matches) === 1) {
            $relative = $matches[1];
            $local = storage_path('app/public/'.$relative);
            if (is_file($local)) {
                return $local;
            }
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{id:int|string,name:string,slug:string}>  $categories
     * @return array<string, mixed>
     */
    protected function normalize(array $data, array $categories): array
    {
        $slug = strtolower((string) ($data['category_slug'] ?? 'keyboards'));
        if (! CategorySchemas::forSlug($slug)) {
            $slug = 'keyboards';
        }

        $condition = $data['condition'] ?? 'Good';
        if (! in_array($condition, ['Like New', 'Good', 'Fair'], true)) {
            $condition = 'Good';
        }

        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        try {
            $attributes = CategorySchemas::assertAttributes($slug, $attributes);
        } catch (\InvalidArgumentException) {
            // Soft-fill: drop invalid keys / keep defaults empty for user edit.
            $attributes = $this->softAttributes($slug, $attributes);
        }

        $category = collect($categories)->firstWhere('slug', $slug);
        $price = $data['suggested_price'] ?? null;
        $suggestedPrice = is_numeric($price) ? round((float) $price, 2) : null;

        $risk = $data['risk_score'] ?? 'medium';
        if (! in_array($risk, ['low', 'medium', 'high'], true)) {
            $risk = 'medium';
        }

        return [
            'categorySlug' => $slug,
            'categoryId' => $category['id'] ?? null,
            'title' => mb_substr(trim((string) ($data['title'] ?? 'Used product')), 0, 200),
            'brand' => mb_substr(trim((string) ($data['brand'] ?? 'Unknown')) ?: 'Unknown', 0, 80),
            'description' => mb_substr(trim((string) ($data['description'] ?? 'Please review product details from your photos.')), 0, 2000),
            'condition' => $condition,
            'attributes' => $attributes,
            'suggestedPrice' => $suggestedPrice,
            'confidence' => max(0, min(1, (float) ($data['confidence'] ?? 0))),
            'flags' => array_values(array_filter(array_map('strval', is_array($data['flags'] ?? null) ? $data['flags'] : []))),
            'riskScore' => $risk,
            'notesForAdmin' => trim((string) ($data['notes_for_admin'] ?? '')),
            'modelVersion' => (string) ($data['model_version'] ?? 'unknown'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function softAttributes(string $slug, array $input): array
    {
        $schema = CategorySchemas::forSlug($slug);
        if (! $schema) {
            return [];
        }

        $out = [];
        foreach ($schema['fields'] as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
                continue;
            }
            $type = $field['type'] ?? 'select';
            $value = $input[$key];
            if ($type === 'boolean') {
                if (is_bool($value)) {
                    $out[$key] = $value;
                } elseif (in_array($value, [1, '1', 'true', 'yes'], true)) {
                    $out[$key] = true;
                } elseif (in_array($value, [0, '0', 'false', 'no'], true)) {
                    $out[$key] = false;
                }
            } elseif ($type === 'number' && is_numeric($value)) {
                $out[$key] = (int) round((float) $value);
            } elseif ($type === 'select' && in_array((string) $value, $field['options'] ?? [], true)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }
}
