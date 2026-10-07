<?php

use App\Http\Controllers\Api\AssignmentsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BulletinsController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\PlatformsController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReviewAnalyticsController;
use App\Http\Controllers\Api\StoriesController;
use App\Http\Controllers\Api\StoryReviewController;
use App\Http\Controllers\Api\StoryTypesController;
use App\Http\Controllers\Api\StoryVersionsController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\TranscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Endpoints — No Auth Required
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Protected Endpoints — Requires Sanctum Session Cookie
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // ── My profile (every role) ───────────────────────────────────────────
    Route::get('/profile',          [ProfileController::class, 'show']);
    Route::patch('/profile',        [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

    // ── Stories (reporters are scoped to their platforms in the controller) ──
    Route::get('stories',          [StoriesController::class, 'index']);
    Route::post('stories',         [StoriesController::class, 'store']);
    Route::get('stories/{story}',  [StoriesController::class, 'show']);
    Route::put('stories/{story}',  [StoriesController::class, 'update']);
    Route::delete('stories/{story}', [StoriesController::class, 'destroy']);
    Route::post('stories/{story}/submit', [StoriesController::class, 'submit']);

    // ── Review workflow (desk checks happen in the controller) ────────────
    Route::post('stories/{story}/claim',     [StoryReviewController::class, 'claim']);
    Route::delete('stories/{story}/claim',   [StoryReviewController::class, 'release']);
    Route::post('stories/{story}/review',    [StoryReviewController::class, 'review']);
    Route::get('stories/{story}/revisions',  [StoryReviewController::class, 'revisions']);
    Route::get('stories/{story}/comments',   [StoryReviewController::class, 'comments']);
    Route::post('stories/{story}/comments',  [StoryReviewController::class, 'addComment']);

    // ── Platform versions ("write once, publish everywhere") ──────────────
    Route::get('stories/{story}/versions',   [StoryVersionsController::class, 'index']);
    Route::post('stories/{story}/versions',  [StoryVersionsController::class, 'store']);
    Route::get('versions',                   [StoryVersionsController::class, 'queue']);
    Route::put('versions/{version}',         [StoryVersionsController::class, 'update']);
    Route::delete('versions/{version}',      [StoryVersionsController::class, 'destroy']);
    Route::post('versions/{version}/review', [StoryVersionsController::class, 'review']);

    // ── Assignments, pitches & the news diary ─────────────────────────────
    Route::get('assignments',                          [AssignmentsController::class, 'index']);
    Route::post('assignments',                         [AssignmentsController::class, 'store']);
    Route::put('assignments/{assignment}',             [AssignmentsController::class, 'update']);
    Route::post('assignments/{assignment}/decide',     [AssignmentsController::class, 'decide']);
    Route::post('assignments/{assignment}/take',       [AssignmentsController::class, 'take']);
    Route::post('assignments/{assignment}/start',      [AssignmentsController::class, 'start']);
    Route::post('assignments/{assignment}/cancel',     [AssignmentsController::class, 'cancel']);
    Route::get('platforms/{platform}/team',            [AssignmentsController::class, 'team']);

    // ── Bulletin rundowns ─────────────────────────────────────────────────
    Route::get('bulletins',                          [BulletinsController::class, 'index']);
    Route::post('bulletins',                         [BulletinsController::class, 'store']);
    Route::get('bulletins/{bulletin}',               [BulletinsController::class, 'show']);
    Route::put('bulletins/{bulletin}',               [BulletinsController::class, 'update']);
    Route::delete('bulletins/{bulletin}',            [BulletinsController::class, 'destroy']);
    Route::post('bulletins/{bulletin}/items',        [BulletinsController::class, 'addItem']);
    Route::put('bulletins/{bulletin}/items/{item}',  [BulletinsController::class, 'updateItem']);
    Route::delete('bulletins/{bulletin}/items/{item}', [BulletinsController::class, 'removeItem']);
    Route::put('bulletins/{bulletin}/order',         [BulletinsController::class, 'reorder']);

    // ── Story types / review-time targets ─────────────────────────────────
    Route::get('story-types', [StoryTypesController::class, 'index']);
    Route::middleware('role:admin,editor')->group(function () {
        Route::post('story-types',                [StoryTypesController::class, 'store']);
        Route::put('story-types/{storyType}',     [StoryTypesController::class, 'update']);
        Route::delete('story-types/{storyType}',  [StoryTypesController::class, 'destroy']);

        Route::get('analytics/review', [ReviewAnalyticsController::class, 'index']);
    });

    // ── Notifications ─────────────────────────────────────────────────────
    Route::get('notifications',             [NotificationsController::class, 'index']);
    Route::post('notifications/read-all',   [NotificationsController::class, 'markAllRead']);
    Route::post('notifications/{id}/read',  [NotificationsController::class, 'markRead']);

    // ── Transcriptions ────────────────────────────────────────────────────
    Route::get    ('transcriptions',              [TranscriptionController::class, 'index']);
    Route::post   ('transcriptions',              [TranscriptionController::class, 'store']);
    Route::get    ('transcriptions/{id}',         [TranscriptionController::class, 'show']);
    Route::patch  ('transcriptions/{id}/seeds',   [TranscriptionController::class, 'updateSeeds']);
    Route::delete ('transcriptions/{id}',         [TranscriptionController::class, 'destroy']);

    // ── Platforms (anyone signed in can list; only admins manage) ─────────
    Route::get('platforms',            [PlatformsController::class, 'index']);

    // ── Admin only ────────────────────────────────────────────────────────
    Route::middleware('role:admin')->group(function () {
        Route::post('platforms',              [PlatformsController::class, 'store']);
        Route::put('platforms/{platform}',    [PlatformsController::class, 'update']);
        Route::patch('platforms/{platform}',  [PlatformsController::class, 'update']);
        Route::delete('platforms/{platform}', [PlatformsController::class, 'destroy']);

        Route::post('/users/invite',              [UserController::class, 'invite']);
        Route::put('/users/{user}/roles',         [UserController::class, 'syncRoles']);
        Route::put('/users/{user}/platforms',     [UserController::class, 'syncPlatforms']);
        Route::patch('/users/{user}/activate',    [UserController::class, 'activate']);
        Route::apiResource('users', UserController::class)->except(['store']);
    });
});
