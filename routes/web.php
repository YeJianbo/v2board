<?php

use App\Services\ThemeService;
use App\Http\Controllers\V2\Admin\BackupController;
use App\Http\Controllers\PublicStatusController;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

$frontendViewData = function (Request $request) {
    if (!(bool) config('v2board.user_frontend_enable', 1)) {
        abort(404);
    }

    if (config('v2board.app_url') && config('v2board.safe_mode_enable', 0)) {
        if ($request->server('HTTP_HOST') !== parse_url(config('v2board.app_url'))['host']) {
            abort(403);
        }
    }
    $renderParams = [
        'title' => config('v2board.app_name', 'V2Board'),
        'theme' => config('v2board.frontend_theme', 'default'),
        'frontend_path' => trim((string) config('v2board.frontend_user_path', 'user'), '/'),
        'version' => config('app.version'),
        'description' => config('v2board.app_description', 'V2Board is best'),
        'logo' => config('v2board.logo')
    ];

    if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $renderParams['frontend_path'])) {
        $renderParams['frontend_path'] = 'user';
    }

    if (!config("theme.{$renderParams['theme']}")) {
        $themeService = new ThemeService($renderParams['theme']);
        $themeService->init();
    }

    $renderParams['theme_config'] = config('theme.' . config('v2board.frontend_theme', 'default'));
    return response()
        ->view('theme::' . config('v2board.frontend_theme', 'default') . '.dashboard', $renderParams)
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache')
        ->header('Expires', '0');
};

$userFrontendPath = trim((string) config('v2board.frontend_user_path', 'user'), '/');
if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $userFrontendPath)) {
    $userFrontendPath = 'user';
}

// Google redirects without the admin bearer token. A short-lived one-time state
// created by the authenticated admin endpoint protects this callback.
Route::get('/api/v2/admin/backup/google/callback', [BackupController::class, 'googleCallback'])
    ->name('admin.backup.google.callback');

// 后台管理端为前端 SPA，除了 secure_path 根路径外，还需要兜底其子路由。
$adminViewData = function () {
    return [
        'title' => config('v2board.app_name', 'V2Board'),
        'theme_sidebar' => config('v2board.frontend_theme_sidebar', 'light'),
        'theme_header' => config('v2board.frontend_theme_header', 'dark'),
        'theme_color' => config('v2board.frontend_theme_color', 'default'),
        'background_url' => config('v2board.frontend_background_url'),
        'version' => config('app.version'),
        'logo' => config('v2board.logo'),
        'secure_path' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))))
    ];
};

Route::get('/', function () use ($userFrontendPath) {
    $mode = config('v2board.homepage_mode', 'monitor');
    if ($mode === 'user') {
        abort_unless((bool) config('v2board.user_frontend_enable', 1), 404);
        return redirect('/' . $userFrontendPath . '/')->header('Cache-Control', 'no-store');
    }
    abort_unless($mode === 'monitor', 404);
    return app(PublicStatusController::class)->index();
});
Route::get('/status.json', [PublicStatusController::class, 'data']);

Route::get('/' . $userFrontendPath, $frontendViewData);
Route::get('/' . $userFrontendPath . '/{any}', $frontendViewData)
    ->where('any', '.*');

Route::get('/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))), function () use ($adminViewData) {
    return response()
        ->view('admin', $adminViewData())
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache')
        ->header('Expires', '0');
});

Route::get('/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))) . '/{any}', function () use ($adminViewData) {
    return response()
        ->view('admin', $adminViewData())
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache')
        ->header('Expires', '0');
})->where('any', '.*');

if (!empty(config('v2board.subscribe_path'))) {
    $subscribePath = trim((string) config('v2board.subscribe_path'), '/');
    Route::get($subscribePath, 'V1\\Client\\ClientController@subscribe')->middleware('client');

    $legacySubscribePath = 'api/v1/client/subscribe';
    if ($subscribePath !== $legacySubscribePath) {
        Route::get($legacySubscribePath, 'V1\\Client\\ClientController@subscribe')->middleware('client');
    }
}
