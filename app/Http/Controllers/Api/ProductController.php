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

        try {
            $product = $this->products->update($product, $user, $input, $request->keys());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
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
}
