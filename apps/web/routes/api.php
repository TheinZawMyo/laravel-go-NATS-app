<?php

use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\BlockController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\ContactRequestController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\ConversationE2eeController;
use App\Http\Controllers\Api\V1\ConversationMediaController;
use App\Http\Controllers\Api\V1\DataExportController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\E2eeBackupController;
use App\Http\Controllers\Api\V1\E2eeKeyController;
use App\Http\Controllers\Api\V1\GatewayTokenController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\GroupInviteController;
use App\Http\Controllers\Api\V1\GroupMemberController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MessageAttachmentController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\MessagePinController;
use App\Http\Controllers\Api\V1\ScheduledMessageController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('v1')->group(function () {
    Route::get('me', [MeController::class, 'show'])->name('api.v1.me');
    Route::get('me/export', DataExportController::class)->name('api.v1.me.export');

    Route::get('gateway/token', GatewayTokenController::class)->name('api.v1.gateway.token');

    Route::get('e2ee/keys', [E2eeKeyController::class, 'show'])->name('api.v1.e2ee.keys.show');
    Route::post('e2ee/keys', [E2eeKeyController::class, 'store'])->name('api.v1.e2ee.keys.store');
    Route::get('e2ee/backup', [E2eeBackupController::class, 'show'])->name('api.v1.e2ee.backup.show');
    Route::put('e2ee/backup', [E2eeBackupController::class, 'store'])->name('api.v1.e2ee.backup.store');

    Route::get('search/conversations', [SearchController::class, 'conversations'])
        ->middleware('throttle:search')
        ->name('api.v1.search.conversations');
    Route::get('search/messages', [SearchController::class, 'messages'])
        ->middleware('throttle:search')
        ->name('api.v1.search.messages');

    Route::get('reports', [UserReportController::class, 'index'])->name('api.v1.reports.index');
    Route::post('reports', [UserReportController::class, 'store'])
        ->middleware('throttle:reports')
        ->name('api.v1.reports.store');
    Route::get('reports/{report}', [UserReportController::class, 'show'])->name('api.v1.reports.show');

    Route::get('users', [UserController::class, 'index'])->name('api.v1.users.index');
    Route::get('users/{username}', [UserController::class, 'show'])->name('api.v1.users.show');

    Route::get('contacts', [ContactController::class, 'index'])->name('api.v1.contacts.index');
    Route::patch('contacts/{username}', [ContactController::class, 'update'])->name('api.v1.contacts.update');
    Route::delete('contacts/{username}', [ContactController::class, 'destroy'])->name('api.v1.contacts.destroy');

    Route::get('contact-requests', [ContactRequestController::class, 'index'])->name('api.v1.contact-requests.index');
    Route::post('contact-requests', [ContactRequestController::class, 'store'])
        ->middleware('throttle:contact-requests')
        ->name('api.v1.contact-requests.store');
    Route::post('contact-requests/{contactRequest}/accept', [ContactRequestController::class, 'accept'])->name('api.v1.contact-requests.accept');
    Route::post('contact-requests/{contactRequest}/reject', [ContactRequestController::class, 'reject'])->name('api.v1.contact-requests.reject');
    Route::post('contact-requests/{contactRequest}/cancel', [ContactRequestController::class, 'cancel'])->name('api.v1.contact-requests.cancel');

    Route::get('blocks', [BlockController::class, 'index'])->name('api.v1.blocks.index');
    Route::post('blocks', [BlockController::class, 'store'])->name('api.v1.blocks.store');
    Route::delete('blocks/{username}', [BlockController::class, 'destroy'])->name('api.v1.blocks.destroy');

    Route::get('devices', [DeviceController::class, 'index'])->name('api.v1.devices.index');
    Route::post('devices', [DeviceController::class, 'store'])->name('api.v1.devices.store');
    Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->name('api.v1.devices.destroy');

    Route::get('conversations', [ConversationController::class, 'index'])->name('api.v1.conversations.index');
    Route::post('conversations/direct', [ConversationController::class, 'storeDirect'])->name('api.v1.conversations.store-direct');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('api.v1.conversations.show');
    Route::get('conversations/{conversation}/e2ee', [ConversationE2eeController::class, 'show'])
        ->name('api.v1.conversations.e2ee.show');
    Route::post('conversations/{conversation}/e2ee/keys', [ConversationE2eeController::class, 'storeKey'])
        ->name('api.v1.conversations.e2ee.keys.store');
    Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])->name('api.v1.conversations.read');
    Route::post('conversations/{conversation}/mute', [ConversationController::class, 'mute'])->name('api.v1.conversations.mute');
    Route::post('conversations/{conversation}/archive', [ConversationController::class, 'archive'])->name('api.v1.conversations.archive');

    Route::post('groups', [GroupController::class, 'store'])->name('api.v1.groups.store');
    Route::patch('groups/{conversation}', [GroupController::class, 'update'])->name('api.v1.groups.update');
    Route::get('groups/{conversation}/members', [GroupController::class, 'members'])->name('api.v1.groups.members.index');
    Route::post('groups/{conversation}/members', [GroupMemberController::class, 'store'])->name('api.v1.groups.members.store');
    Route::delete('groups/{conversation}/members/{username}', [GroupMemberController::class, 'destroy'])->name('api.v1.groups.members.destroy');
    Route::post('groups/{conversation}/members/{username}/role', [GroupMemberController::class, 'updateRole'])->name('api.v1.groups.members.role');
    Route::post('groups/{conversation}/leave', [GroupMemberController::class, 'leave'])->name('api.v1.groups.leave');

    Route::get('groups/{conversation}/invites', [GroupInviteController::class, 'index'])->name('api.v1.groups.invites.index');
    Route::post('groups/{conversation}/invites', [GroupInviteController::class, 'store'])->name('api.v1.groups.invites.store');
    Route::delete('groups/{conversation}/invites/{invite}', [GroupInviteController::class, 'destroy'])->name('api.v1.groups.invites.destroy');
    Route::get('invites/{token}', [GroupInviteController::class, 'preview'])->name('api.v1.invites.preview');
    Route::post('invites/{token}/join', [GroupInviteController::class, 'redeem'])->name('api.v1.invites.redeem');

    Route::get('conversations/{conversation}/pins', [MessagePinController::class, 'index'])->name('api.v1.pins.index');
    Route::post('messages/{message}/pin', [MessagePinController::class, 'store'])->name('api.v1.pins.store');
    Route::delete('messages/{message}/pin', [MessagePinController::class, 'destroy'])->name('api.v1.pins.destroy');

    Route::get('conversations/{conversation}/messages', [MessageController::class, 'index'])->name('api.v1.messages.index');
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->middleware('throttle:messages')
        ->name('api.v1.messages.store');
    Route::get('conversations/{conversation}/scheduled-messages', [ScheduledMessageController::class, 'index'])
        ->name('api.v1.scheduled-messages.index');
    Route::post('conversations/{conversation}/scheduled-messages', [ScheduledMessageController::class, 'store'])
        ->middleware('throttle:messages')
        ->name('api.v1.scheduled-messages.store');
    Route::delete('scheduled-messages/{scheduledMessage}', [ScheduledMessageController::class, 'destroy'])
        ->name('api.v1.scheduled-messages.destroy');
    Route::patch('messages/{message}', [MessageController::class, 'update'])->name('api.v1.messages.update');
    Route::delete('messages/{message}', [MessageController::class, 'destroy'])->name('api.v1.messages.destroy');

    Route::get('conversations/{conversation}/media', [ConversationMediaController::class, 'index'])->name('api.v1.conversations.media');
    Route::get('messages/{message}/attachments/{attachment}', [MessageAttachmentController::class, 'download'])->name('api.v1.attachments.download');

    Route::post('uploads/presign', [UploadController::class, 'presign'])
        ->middleware('throttle:uploads')
        ->name('api.v1.uploads.presign');

    /*
     | Moderation is intentionally a separate prefix. Every route below is
     | protected by the `admin` middleware and must write an audit log entry.
     */
    Route::middleware('admin')->prefix('admin')->group(function (): void {
        Route::get('reports', [AdminReportController::class, 'index'])->name('api.v1.admin.reports.index');
        Route::post('reports/{report}/resolve', [AdminReportController::class, 'resolve'])->name('api.v1.admin.reports.resolve');

        Route::post('users/{username}/moderate', [AdminUserController::class, 'moderate'])->name('api.v1.admin.users.moderate');

        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('api.v1.admin.audit-logs.index');
    });
});

/*
 | The presigned upload receiver is authenticated by the URL signature rather
 | than by the session, so it lives outside the auth group. CSRF is exempted
 | for it in bootstrap/app.php for the same reason.
 */
Route::middleware(['web'])->prefix('v1')->group(function (): void {
    Route::put('uploads/{upload}', [UploadController::class, 'receive'])
        ->middleware('throttle:upload-receive')
        ->name('api.v1.uploads.receive');
});
