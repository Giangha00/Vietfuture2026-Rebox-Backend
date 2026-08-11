<?php

use App\Http\Controllers\Api\AiListingDraftController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OfferController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\JwtAuthenticate;
use App\Http\Middleware\RequireVerified;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'rebox-backend',
]));

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('resend-verification', [AuthController::class, 'resendVerification']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('verify-reset-otp', [AuthController::class, 'verifyResetOtp']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware([JwtAuthenticate::class])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::patch('me', [AuthController::class, 'updateMe']);
    });
});

Route::get('categories', [LookupController::class, 'categories']);
Route::get('stations', [LookupController::class, 'stations']);
Route::get('offers/options', [OfferController::class, 'options']);

Route::get('products', [ProductController::class, 'index']);

Route::middleware([JwtAuthenticate::class, RequireVerified::class])->group(function () {
    Route::get('products/mine', [ProductController::class, 'mine']);
    Route::post('products', [ProductController::class, 'store']);
    Route::patch('products/{id}', [ProductController::class, 'update']);
    Route::delete('products/{id}', [ProductController::class, 'destroy']);

    Route::post('uploads', [UploadController::class, 'store']);
    Route::post('ai/listing-draft', [AiListingDraftController::class, 'store']);

    Route::get('offers/mine', [OfferController::class, 'mine']);
    Route::get('offers/selling', [OfferController::class, 'selling']);
    Route::post('offers', [OfferController::class, 'store']);
    Route::get('offers/{id}', [OfferController::class, 'show']);
    Route::post('offers/{id}/accept', [OfferController::class, 'accept']);
    Route::post('offers/{id}/reject', [OfferController::class, 'reject']);
    Route::post('offers/{id}/cancel', [OfferController::class, 'cancel']);

    Route::get('orders/events/stream', [OrderController::class, 'stream']);
    Route::get('orders/mine', [OrderController::class, 'mine']);
    Route::get('orders/selling', [OrderController::class, 'selling']);
    Route::get('orders/shipper/jobs', [OrderController::class, 'shipperJobs'])
        ->middleware([EnsureRole::class.':shipper,admin']);
    Route::post('orders/auto-complete', [OrderController::class, 'autoComplete'])
        ->middleware([EnsureRole::class.':admin']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::get('orders/{id}', [OrderController::class, 'show']);
    Route::post('orders/{id}/pay', [OrderController::class, 'pay']);
    Route::post('orders/{id}/capture-payment', [OrderController::class, 'capturePayment']);
    Route::post('orders/{id}/seller-confirm', [OrderController::class, 'sellerConfirm']);
    Route::post('orders/{id}/seller-reject', [OrderController::class, 'sellerReject']);
    Route::post('orders/{id}/cancel', [OrderController::class, 'cancel']);
    Route::post('orders/{id}/assign-shipper', [OrderController::class, 'assignShipper']);
    Route::post('orders/{id}/shipper-status', [OrderController::class, 'shipperStatus']);
    Route::post('orders/{id}/confirm-delivery', [OrderController::class, 'confirmDelivery']);
    Route::post('orders/{id}/dispute', [OrderController::class, 'dispute']);
    Route::post('orders/{id}/resolve-dispute', [OrderController::class, 'resolveDispute'])
        ->middleware([EnsureRole::class.':admin']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/fcm-token', [NotificationController::class, 'registerFcmToken']);
    Route::delete('notifications/fcm-token', [NotificationController::class, 'removeFcmToken']);
    Route::patch('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'readOne']);
    Route::post('notifications/test', [NotificationController::class, 'test']);
    Route::post('notifications/cart', [NotificationController::class, 'cart']);
});

Route::get('products/{id}', [ProductController::class, 'show'])
    ->middleware([JwtAuthenticate::class.':optional']);

Route::prefix('payments')->group(function () {
    Route::get('paypal/status', [PaymentController::class, 'status']);
    Route::get('paypal/return', [PaymentController::class, 'paypalReturn']);
    Route::get('paypal/cancel', [PaymentController::class, 'paypalCancel']);
});
