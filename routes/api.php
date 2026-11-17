<?php

use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\CluePostController;
use App\Http\Controllers\Api\GeoController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MiscController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\SupportController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/wechat', [\App\Http\Controllers\Api\AuthController::class, 'wechat'])->middleware('throttle:10,1');
Route::post('/pay/wechat/notify', [\App\Http\Controllers\Api\WechatPayController::class, 'notify']);

Route::get('/banners', [BannerController::class, 'index']);
Route::get('/me', [MeController::class, 'show']);
Route::patch('/me', [MeController::class, 'update']);
Route::post('/me/visit', [MeController::class, 'visit']);
Route::get('/me/growth', [MeController::class, 'growth']);
Route::get('/settings', [MeController::class, 'settings']);
Route::get('/support', [SupportController::class, 'show']);
Route::post('/support/orders', [SupportController::class, 'store'])->middleware('throttle:10,1');
Route::get('/support/orders/{supportOrder}', [SupportController::class, 'order'])->whereNumber('supportOrder');
Route::get('/rules', [MiscController::class, 'rules']);
Route::get('/rescue-team', [MiscController::class, 'rescueTeam']);
Route::get('/contact-platform', [MiscController::class, 'contactPlatform']);
Route::get('/notifications', [MiscController::class, 'notifications']);
Route::post('/notifications/{notification}/read', [MiscController::class, 'readNotification']);
Route::get('/geo/city', [GeoController::class, 'city'])->middleware('throttle:30,1');

Route::post('/uploads', [PostController::class, 'upload'])->middleware('throttle:30,1');

Route::get('/clue-posts', [CluePostController::class, 'index']);
Route::post('/clue-posts', [CluePostController::class, 'store']);
Route::get('/my/clue-posts', [CluePostController::class, 'mine']);
Route::get('/my/clue-contact-requests/pending-count', [CluePostController::class, 'pendingContactCount']);
Route::get('/my/clue-contact-requests', [CluePostController::class, 'myRequests']);
Route::get('/clue-posts/{cluePost}', [CluePostController::class, 'show']);
Route::post('/clue-posts/{cluePost}/light', [CluePostController::class, 'light']);
Route::post('/clue-posts/{cluePost}/share', [CluePostController::class, 'share']);
Route::post('/clue-posts/{cluePost}/withdraw', [CluePostController::class, 'withdraw']);
Route::patch('/clue-posts/{cluePost}/contact', [CluePostController::class, 'updateContact']);
Route::post('/clue-posts/{cluePost}/contact-requests', [CluePostController::class, 'storeRequest']);
Route::post('/clue-contact-requests/{clueContactRequest}/approve', [CluePostController::class, 'approveRequest']);
Route::post('/clue-contact-requests/{clueContactRequest}/reject', [CluePostController::class, 'rejectRequest']);
Route::post('/clue-contact-requests/{clueContactRequest}/cancel', [CluePostController::class, 'cancelRequest']);

Route::get('/posts', [PostController::class, 'index']);
Route::post('/posts', [PostController::class, 'store']);
Route::get('/my/posts', [PostController::class, 'mine']);
Route::get('/my/leads', [LeadController::class, 'mine']);
Route::get('/my/incoming-leads/unread-count', [LeadController::class, 'unreadCount']);
Route::get('/my/incoming-leads', [LeadController::class, 'incoming']);
Route::get('/posts/trace', [PostController::class, 'trace']);
Route::get('/posts/{post}', [PostController::class, 'show']);
Route::post('/posts/{post}/pay', [PostController::class, 'pay'])->middleware('throttle:10,1');
Route::post('/posts/{post}/reward', [PostController::class, 'addReward'])->middleware('throttle:10,1');
Route::post('/posts/{post}/withdraw', [PostController::class, 'withdraw']);
Route::post('/posts/{post}/light', [PostController::class, 'light']);
Route::post('/posts/{post}/share', [PostController::class, 'share']);
Route::get('/posts/{post}/leads', [LeadController::class, 'index']);
Route::post('/posts/{post}/leads', [LeadController::class, 'store']);
Route::post('/posts/{post}/appeals', [LeadController::class, 'appeal']);

Route::get('/leads/{lead}', [LeadController::class, 'show']);
Route::post('/leads/{lead}/match', [LeadController::class, 'match']);
Route::post('/leads/{lead}/acknowledge', [LeadController::class, 'acknowledge']);
Route::post('/leads/{lead}/reject', [LeadController::class, 'reject']);
Route::post('/leads/{lead}/confirm', [LeadController::class, 'confirm']);
Route::post('/leads/{lead}/not-mine', [LeadController::class, 'notMine']);
