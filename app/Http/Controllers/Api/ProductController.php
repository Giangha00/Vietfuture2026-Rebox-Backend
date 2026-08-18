<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiTrainingSample;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\NotificationService;
use App\Support\CategorySchemas;
use App\Support\Money;
use App\Support\Serializers;
use App\Support\Validators;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        protected NotificationService $notifications,
        protected AdminNotificationService $adminNotifications,
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

        $title = trim((string) $request->input('title', ''));
        $description = trim((string) $request->input('description', ''));
        $condition = $request->input('condition', 'Good');
        $categoryId = $request->input('category');
        $images = $request->input('images', []);
        $acceptsOffers = $request->boolean('acceptsOffers', true);

        if (mb_strlen($title) < 3) {
            return response()->json(['message' => 'Title must be at least 3 characters.'], 400);
        }
        if (mb_strlen($description) < 10) {
            return response()->json(['message' => 'Description must be at least 10 characters.'], 400);
        }
        if (! in_array($condition, ['Like New', 'Good', 'Fair'], true)) {
            return response()->json(['message' => 'Invalid condition.'], 400);
        }

        $category = Category::query()->find($categoryId);
        if (! $category) {
            return response()->json(['message' => 'Category not found.'], 400);
        }

        $pickup = Validators::normalizeAddress(
            $request->input('pickupAddress') ?: ($user->pickup_address ?? [])
        );
        if ($pickup['line1'] === '' || $pickup['city'] === '') {
            return response()->json([
                'message' => 'Pickup address is required (street and city). Couriers will collect from this address when your item sells.',
            ], 400);
        }
        if ($pickup['fullName'] === '') {
            $pickup['fullName'] = (string) ($user->full_name ?? '');
        }
        if ($pickup['phone'] === '') {
            $pickup['phone'] = (string) ($user->phone ?? '');
        }
        $user->pickup_address = $pickup;
        $user->save();

        try {
            $price = Money::assertProductPrice($request->input('price'));
            $brand = CategorySchemas::assertBrand($request->input('brand'));
            $productAttributes = CategorySchemas::assertAttributes(
                $category->slug,
                $request->input('attributes', [])
            );
        } catch (\InvalidArgumentException $e) {
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

        if ($product->moderation_status === 'rejected') {
            return response()->json([
                'message' => 'This product was rejected and cannot be edited. Please create a new listing to sell again.',
            ], 400);
        }

        $onlyOffers = $request->keys() === ['acceptsOffers']
            || (count($request->except(['acceptsOffers'])) === 0 && $request->has('acceptsOffers'));

        if ($request->has('title')) {
            $title = trim((string) $request->input('title'));
            if (mb_strlen($title) < 3) {
                return response()->json(['message' => 'Title must be at least 3 characters.'], 400);
            }
            $product->title = $title;
        }
        if ($request->has('description')) {
            $description = trim((string) $request->input('description'));
            if (mb_strlen($description) < 10) {
                return response()->json(['message' => 'Description must be at least 10 characters.'], 400);
            }
            $product->description = $description;
        }
        if ($request->has('price')) {
            try {
                $product->price = Money::assertProductPrice($request->input('price'));
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 400);
            }
        }
        if ($request->has('condition')) {
            $condition = $request->input('condition');
            if (! in_array($condition, ['Like New', 'Good', 'Fair'], true)) {
                return response()->json(['message' => 'Invalid condition.'], 400);
            }
            $product->condition = $condition;
        }
        if ($request->has('images')) {
            $product->images = is_array($request->input('images')) ? $request->input('images') : [];
        }
        if ($request->has('category')) {
            $category = Category::query()->find($request->input('category'));
            if (! $category) {
                return response()->json(['message' => 'Category not found.'], 400);
            }
            $product->category_id = $category->id;
        }
        if ($request->has('acceptsOffers')) {
            $product->accepts_offers = $request->boolean('acceptsOffers');
        }

        $needsAttributeRefresh = $request->has('category')
            || $request->has('attributes')
            || $request->has('brand');

        if ($needsAttributeRefresh && ! $onlyOffers) {
            $product->loadMissing('category');
            $slug = $product->category?->slug;
            try {
                if ($request->has('brand') || $product->brand === null || $product->brand === '') {
                    $product->brand = CategorySchemas::assertBrand(
                        $request->has('brand') ? $request->input('brand') : $product->brand
                    );
                }
                if ($request->has('attributes') || $request->has('category')) {
                    $product->attributes = CategorySchemas::assertAttributes(
                        $slug,
                        $request->has('attributes')
                            ? $request->input('attributes', [])
                            : ($product->attributes ?? [])
                    );
                }
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 400);
            }
        }

        if (! $onlyOffers) {
            $product->moderation_status = 'pending';
            $product->is_verified = false;
            $product->rejection_reason = '';
            $product->moderation_notes = null;
            $product->moderated_at = null;
            $product->moderated_by = null;
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
