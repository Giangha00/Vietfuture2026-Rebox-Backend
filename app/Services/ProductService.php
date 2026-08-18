<?php

namespace App\Services;

use App\Models\AiTrainingSample;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\CategorySchemas;
use App\Support\Money;
use App\Support\Validators;
use InvalidArgumentException;

class ProductService
{
    public function __construct(
        protected NotificationService $notifications,
        protected AdminNotificationService $adminNotifications,
    ) {}

    /**
     * Create a product listing (pending moderation).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException
     */
    public function create(User $user, array $input): Product
    {
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $condition = $input['condition'] ?? 'Good';
        $categoryId = $input['category'] ?? null;
        $images = $input['images'] ?? [];
        $acceptsOffers = array_key_exists('acceptsOffers', $input)
            ? (bool) $input['acceptsOffers']
            : true;

        if (mb_strlen($title) < 3) {
            throw new InvalidArgumentException('Title must be at least 3 characters.');
        }
        if (mb_strlen($description) < 10) {
            throw new InvalidArgumentException('Description must be at least 10 characters.');
        }
        if (! in_array($condition, ['Like New', 'Good', 'Fair'], true)) {
            throw new InvalidArgumentException('Invalid condition.');
        }

        $category = Category::query()->find($categoryId);
        if (! $category) {
            throw new InvalidArgumentException('Category not found.');
        }

        $pickup = Validators::normalizeAddress(
            ($input['pickupAddress'] ?? null) ?: ($user->pickup_address ?? [])
        );
        if ($pickup['line1'] === '' || $pickup['city'] === '') {
            throw new InvalidArgumentException(
                'Pickup address is required (street and city). Couriers will collect from this address when your item sells.'
            );
        }
        if ($pickup['fullName'] === '') {
            $pickup['fullName'] = (string) ($user->full_name ?? '');
        }
        if ($pickup['phone'] === '') {
            $pickup['phone'] = (string) ($user->phone ?? '');
        }
        $user->pickup_address = $pickup;
        $user->save();

        $price = Money::assertProductPrice($input['price'] ?? null);
        $brand = CategorySchemas::assertBrand($input['brand'] ?? null);
        $productAttributes = CategorySchemas::assertAttributes(
            $category->slug,
            $input['attributes'] ?? []
        );

        $aiMetaRaw = $input['aiMeta'] ?? $input['ai_meta'] ?? null;

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
            'ai_meta' => $this->normalizeAiMeta($aiMetaRaw),
            'moderation_notes' => $this->aiNotesForAdmin($aiMetaRaw),
        ]);

        $this->recordTrainingSample($product, $user, $category->slug, $productAttributes);

        $this->notifications->createAndPush(
            $user,
            'Listing submitted',
            "Your listing \"{$product->title}\" is awaiting admin review.",
            'listing',
            '/products/'.$product->id,
            ['productId' => (string) $product->id]
        );

        $this->adminNotifications->productPendingReview($product);

        return $product;
    }

    /**
     * Update a product listing. Resubmits for moderation unless only acceptsOffers changed.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $presentKeys  Keys present on the request (for partial updates).
     *
     * @throws InvalidArgumentException
     */
    public function update(Product $product, User $user, array $input, array $presentKeys): Product
    {
        if ($product->moderation_status === 'rejected') {
            throw new InvalidArgumentException(
                'This product was rejected and cannot be edited. Please create a new listing to sell again.'
            );
        }

        $has = static fn (string $key): bool => in_array($key, $presentKeys, true);

        $onlyOffers = $presentKeys === ['acceptsOffers']
            || (count(array_diff($presentKeys, ['acceptsOffers'])) === 0 && $has('acceptsOffers'));

        if ($has('title')) {
            $title = trim((string) ($input['title'] ?? ''));
            if (mb_strlen($title) < 3) {
                throw new InvalidArgumentException('Title must be at least 3 characters.');
            }
            $product->title = $title;
        }
        if ($has('description')) {
            $description = trim((string) ($input['description'] ?? ''));
            if (mb_strlen($description) < 10) {
                throw new InvalidArgumentException('Description must be at least 10 characters.');
            }
            $product->description = $description;
        }
        if ($has('price')) {
            $product->price = Money::assertProductPrice($input['price'] ?? null);
        }
        if ($has('condition')) {
            $condition = $input['condition'] ?? null;
            if (! in_array($condition, ['Like New', 'Good', 'Fair'], true)) {
                throw new InvalidArgumentException('Invalid condition.');
            }
            $product->condition = $condition;
        }
        if ($has('images')) {
            $product->images = is_array($input['images'] ?? null) ? $input['images'] : [];
        }
        if ($has('category')) {
            $category = Category::query()->find($input['category'] ?? null);
            if (! $category) {
                throw new InvalidArgumentException('Category not found.');
            }
            $product->category_id = $category->id;
        }
        if ($has('acceptsOffers')) {
            $product->accepts_offers = (bool) $input['acceptsOffers'];
        }

        $needsAttributeRefresh = $has('category') || $has('attributes') || $has('brand');

        if ($needsAttributeRefresh && ! $onlyOffers) {
            $product->loadMissing('category');
            $slug = $product->category?->slug;
            if ($has('brand') || $product->brand === null || $product->brand === '') {
                $product->brand = CategorySchemas::assertBrand(
                    $has('brand') ? ($input['brand'] ?? null) : $product->brand
                );
            }
            if ($has('attributes') || $has('category')) {
                $product->attributes = CategorySchemas::assertAttributes(
                    $slug,
                    $has('attributes')
                        ? ($input['attributes'] ?? [])
                        : ($product->attributes ?? [])
                );
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

        return $product;
    }

    /**
     * @param  mixed  $raw
     * @return array<string, mixed>|null
     */
    public function normalizeAiMeta(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        return $raw;
    }

    public function aiNotesForAdmin(mixed $raw): string
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
    public function recordTrainingSample(
        Product $product,
        User $user,
        string $categorySlug,
        array $attributes
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
        ]);
    }
}
