<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ProductService;
use App\Support\Serializers;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ProductController extends Controller
{
    public function __construct(
        protected NotificationService $notifications,
        protected ProductService $products,
    ) {}

    public function index()
    {
        $products = Product::query()
            ->with(['seller', 'category', 'station'])
            ->where('moderation_status', 'approved')
            ->where('status', 'active')
            ->latest()
            ->get()
            ->map(fn (Product $p) => Serializers::product($p));

        return response()->json(['products' => $products]);
    }

    public function mine(Request $request)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $products = Product::query()
            ->with(['seller', 'category', 'station'])
            ->where('seller_id', $user->id)
            ->latest()
            ->get()
            ->map(fn (Product $p) => Serializers::product($p));

        return response()->json(['products' => $products]);
    }

    public function show(Request $request, string $id)
    {
        $product = Product::query()->with(['seller', 'category', 'station'])->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $user = $request->attributes->get('authUser');
        $isOwner = $user && (int) $user->id === (int) $product->seller_id;
        if ($product->moderation_status !== 'approved' && ! $isOwner) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json(['product' => Serializers::product($product)]);
    }

    public function store(Request $request)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');

        $input = $request->all();
        $input['acceptsOffers'] = $request->boolean('acceptsOffers', true);

        try {
            $product = $this->products->create($user, $input);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        $product = Product::query()->create([
            'title' => $title,
            'brand' => $brand,
            'description' => $description,
            'price' => $price,
            'condition' => $condition,
            'attributes' => $productAttributes,
            'images' => is_array($images) ? $images : [],
            'category_id' => $category->id,
            'station_id' => null,
            'seller_id' => $user->id,
            'moderation_status' => 'pending',
            'is_verified' => false,
            'status' => 'active',
            'accepts_offers' => $acceptsOffers,
            'ai_meta' => $this->normalizeAiMeta($request->input('aiMeta', $request->input('ai_meta'))),
            'moderation_notes' => $this->aiNotesForAdmin($request->input('aiMeta', $request->input('ai_meta'))),
        ]);

        $this->recordTrainingSample($product, $user, $category->slug, $productAttributes, 'user');

        $this->notifications->createAndPush(
            $user,
            'Listing submitted',
            "Your listing \"{$product->title}\" is awaiting admin review.",
            'listing',
            '/products/'.$product->id,
            ['productId' => (string) $product->id]
        );

        $this->adminNotifications->productPendingReview($product);

        return response()->json([
            'message' => 'Product submitted for admin review.',
            'product' => Serializers::product($product->fresh(['seller', 'category', 'station'])),
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }
        if ((int) $product->seller_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $input = $request->all();
        if ($request->has('acceptsOffers')) {
            $input['acceptsOffers'] = $request->boolean('acceptsOffers');
        }

        $product->save();

        if (! $onlyOffers) {
            $product->loadMissing('category');
            $slug = $product->category?->slug ?? 'keyboards';
            $attrs = is_array($product->attributes) ? $product->attributes : [];
            // Fresh sample so edits re-enter the pool (admin_label null until re-approved).
            $this->recordTrainingSample($product, $user, $slug, $attrs, 'user');

            $this->notifications->createAndPush(
                $user,
                'Listing updated',
                "Your listing \"{$product->title}\" was resubmitted for review.",
                'listing',
                '/products/'.$product->id,
                ['productId' => (string) $product->id]
            );
            $this->adminNotifications->productPendingReview($product);
        }

        return response()->json([
            'message' => 'Product updated.',
            'product' => Serializers::product($product->fresh(['seller', 'category', 'station'])),
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        /** @var User $user */
        $user = $request->attributes->get('authUser');
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }
        if ((int) $product->seller_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $title = $product->title;
        $product->delete();

        $this->notifications->createAndPush(
            $user,
            'Listing deleted',
            "Your listing \"{$title}\" was deleted.",
            'listing'
        );

        return response()->json(['message' => 'Product deleted.']);
    }

    /**
     * @param  mixed  $raw
     * @return array<string, mixed>|null
     */
    protected function normalizeAiMeta(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        return $raw;
    }

    protected function aiNotesForAdmin(mixed $raw): string
    {
        if (! is_array($raw)) {
            return '';
        }
        $notes = $raw['notes_for_admin'] ?? $raw['notesForAdmin'] ?? null;
        if (is_string($notes) && trim($notes) !== '') {
            return trim($notes);
        }
        $draft = is_array($raw['draft'] ?? null) ? $raw['draft'] : [];
        $fromDraft = $draft['notesForAdmin'] ?? $draft['notes_for_admin'] ?? '';

        return is_string($fromDraft) ? trim($fromDraft) : '';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function recordTrainingSample(
        Product $product,
        User $user,
        string $categorySlug,
        array $attributes,
        string $source = 'user'
    ): void {
        $meta = is_array($product->ai_meta) ? $product->ai_meta : [];
        $draft = is_array($meta['draft'] ?? null) ? $meta['draft'] : null;
        $images = is_array($product->images) ? $product->images : [];
        if ($images === [] && is_array($meta['image_urls'] ?? null)) {
            $images = $meta['image_urls'];
        }

        AiTrainingSample::query()->create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'image_urls' => $images,
            'category_slug' => $categorySlug,
            'ai_draft' => $draft,
            'user_final' => [
                'title' => $product->title,
                'brand' => $product->brand,
                'description' => $product->description,
                'condition' => $product->condition,
                'price' => (float) $product->price,
                'category_slug' => $categorySlug,
                'attributes' => $attributes,
            ],
            'admin_label' => null,
            'rejection_reason' => null,
            'model_version' => is_string($meta['model'] ?? null)
                ? $meta['model']
                : (is_string($draft['modelVersion'] ?? null) ? $draft['modelVersion'] : null),
            'source' => $source !== '' ? $source : 'user',
            'exported_at' => null,
        ]);
    }
}