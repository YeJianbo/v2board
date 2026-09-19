<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\ServerMetadataService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use DispatchesJobs, ValidatesRequests, ApiResponse;

    protected function validateServerMetadata(Request $request): ?array
    {
        return app(ServerMetadataService::class)->validateRequest($request);
    }

    protected function saveServerMetadata(string $serverType, int $serverId, ?array $metadata): void
    {
        app(ServerMetadataService::class)->save($serverType, $serverId, $metadata);
    }

    protected function deleteServerMetadata(string $serverType, int $serverId): void
    {
        app(ServerMetadataService::class)->delete($serverType, $serverId);
    }

    protected function copyServerMetadata(string $serverType, int $sourceServerId, int $targetServerId): void
    {
        app(ServerMetadataService::class)->copy($serverType, $sourceServerId, $targetServerId);
    }
}
