<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\PluginConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PluginController extends Controller
{
    protected PluginManager $pluginManager;
    protected PluginConfigService $configService;

    public function __construct(
        PluginManager $pluginManager,
        PluginConfigService $configService
    ) {
        $this->pluginManager = $pluginManager;
        $this->configService = $configService;
    }

    /**
     * 获取所有插件类型
     */
    public function types()
    {
        return response()->json([
            'data' => [
                [
                    'label' => '功能插件',
                    'value' => 'feature',
                ],
                [
                    'label' => '支付插件',
                    'value' => 'payment',
                ]
            ]
        ]);
    }

    /**
     * 获取插件列表
     */
    public function index(Request $request)
    {
        $this->pluginManager->initializeEnabledPlugins();
        $initializationErrors = $this->pluginManager->getInitializationErrors();
        $databasePlugins = collect();
        if (Schema::hasTable((new Plugin())->getTable())) {
            $databasePlugins = Plugin::query()->get()->keyBy('code');
        }

        $plugins = collect();
        foreach ($this->pluginManager->getPluginPaths() as $basePath) {
            if (!File::isDirectory($basePath)) {
                continue;
            }

            foreach (File::directories($basePath) as $directory) {
                $configPath = $directory . '/config.json';
                if (!File::isFile($configPath)) {
                    continue;
                }

                $manifest = json_decode(File::get($configPath), true);
                $code = is_array($manifest) ? ($manifest['code'] ?? null) : null;
                if (!$code) {
                    continue;
                }

                $installed = $databasePlugins->get($code);
                $isCore = $this->pluginManager->isCorePlugin($code);
                $readmePath = collect(['README.md', 'README.MD', 'readme.md'])
                    ->map(fn($name) => $directory . '/' . $name)
                    ->first(fn($path) => File::isFile($path));
                $currentVersion = (string) ($installed?->version ?? '');
                $availableVersion = (string) ($manifest['version'] ?? '');
                $entryFileExists = File::isFile($directory . '/Plugin.php');
                $runtimeError = $initializationErrors[$code] ?? null;
                $runtimeStatus = !$installed
                    ? 'available'
                    : (!($installed->is_enabled ?? false)
                        ? 'disabled'
                        : (($entryFileExists && !$runtimeError) ? 'running' : 'error'));

                $plugins->put($code, [
                    'code' => $code,
                    'name' => $manifest['name'] ?? $installed?->name ?? $code,
                    'version' => $availableVersion ?: ($currentVersion ?: null),
                    'installed_version' => $currentVersion ?: null,
                    'author' => $manifest['author'] ?? null,
                    'type' => $manifest['type'] ?? $installed?->type ?? Plugin::TYPE_FEATURE,
                    'description' => $manifest['description'] ?? null,
                    'is_installed' => (bool) $installed,
                    'is_enabled' => (bool) ($installed?->is_enabled ?? false),
                    'is_protected' => $isCore,
                    'can_be_deleted' => !$isCore && !($installed?->is_enabled ?? false),
                    'need_upgrade' => $installed
                        && $availableVersion !== ''
                        && version_compare($availableVersion, $currentVersion, '>'),
                    'runtime_status' => $runtimeStatus,
                    'runtime_error' => $runtimeError ?: ($entryFileExists ? null : '缺少 Plugin.php 入口文件'),
                    'config' => $this->configService->getConfig($code),
                    'readme' => $readmePath ? substr(File::get($readmePath), 0, 200000) : '',
                ]);
            }
        }

        foreach ($databasePlugins as $code => $installed) {
            if ($plugins->has($code)) {
                continue;
            }
            $plugins->put($code, [
                'code' => $code,
                'name' => $installed->name ?: $code,
                'version' => $installed->version,
                'installed_version' => $installed->version,
                'author' => null,
                'type' => $installed->type ?: Plugin::TYPE_FEATURE,
                'description' => '插件文件不存在，请重新上传插件包',
                'is_installed' => true,
                'is_enabled' => (bool) $installed->is_enabled,
                'is_protected' => false,
                'can_be_deleted' => !(bool) $installed->is_enabled,
                'need_upgrade' => false,
                'runtime_status' => 'error',
                'runtime_error' => $initializationErrors[$code] ?? '插件目录或配置文件不存在',
                'config' => [],
                'readme' => '',
            ]);
        }

        $type = trim((string) $request->input('type', ''));
        $status = trim((string) $request->input('status', ''));
        $plugins = $plugins->values()->filter(function (array $plugin) use ($type, $status) {
            if ($type !== '' && $plugin['type'] !== $type) {
                return false;
            }

            $pluginStatus = !$plugin['is_installed']
                ? 'not_installed'
                : ($plugin['runtime_status'] === 'error'
                    ? 'error'
                    : ($plugin['is_enabled'] ? 'enabled' : 'disabled'));
            return $status === '' || $pluginStatus === $status;
        })->values();

        return response()->json(['data' => $plugins]);
    }

    /**
     * 安装插件
     */
    public function install(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        try {
            $this->pluginManager->install($request->input('code'));
            return response()->json([
                'message' => '插件安装成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件安装失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 卸载插件
     */
    public function uninstall(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        $code = $request->input('code');
        // $plugin = Plugin::where('code', $code)->first();
        // if ($plugin && $plugin->is_enabled) {
        //     return response()->json([
        //         'message' => '请先禁用插件后再卸载'
        //     ], 400);
        // }

        try {
            $this->pluginManager->uninstall($code);
            return response()->json([
                'message' => '插件卸载成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件卸载失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 升级插件
     */
    public function upgrade(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);
        try {
            $this->pluginManager->update($request->input('code'));
            return response()->json([
                'message' => '插件升级成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件升级失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 启用插件
     */
    public function enable(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        try {
            $this->pluginManager->enable($request->input('code'));
            return response()->json([
                'message' => '插件启用成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件启用失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 禁用插件
     */
    public function disable(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        try {
            $this->pluginManager->disable($request->input('code'));
            return response()->json([
                'message' => '插件禁用成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件禁用失败：' . $e->getMessage()
            ], 400);
        }

    }

    /**
     * 获取插件配置
     */
    public function getConfig(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        try {
            if (!Schema::hasTable((new \App\Models\Plugin())->getTable())) {
                return response()->json([
                    'message' => '插件表未初始化'
                ], 400);
            }
            $config = $this->configService->getConfig($request->input('code'));
            return response()->json([
                'data' => $config
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '获取配置失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 更新插件配置
     */
    public function updateConfig(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'config' => 'required|array'
        ]);

        try {
            $this->configService->updateConfig(
                $request->input('code'),
                $request->input('config')
            );

            return response()->json([
                'message' => '配置更新成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '配置更新失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 上传插件
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:zip',
                'max:20480',
            ]
        ], [
            'file.required' => '请选择插件包文件',
            'file.file' => '无效的文件类型',
            'file.mimes' => '插件包必须是zip格式',
            'file.max' => '插件包大小不能超过20MB'
        ]);

        try {
            $this->pluginManager->upload($request->file('file'));
            return response()->json([
                'message' => '插件上传成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件上传失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 删除插件
     */
    public function delete(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        $code = $request->input('code');

        // 检查是否为核心插件
        if ($this->pluginManager->isCorePlugin($code)) {
            return response()->json([
                'message' => '该插件为系统核心插件，不允许删除'
            ], 403);
        }

        try {
            $this->pluginManager->delete($code);
            return response()->json([
                'message' => '插件删除成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件删除失败：' . $e->getMessage()
            ], 400);
        }
    }
}
