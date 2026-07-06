<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Services\Portal\AdminPortalService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminPortalController extends Controller
{
    public function __construct(
        private readonly AdminPortalService $adminPortalService,
    ) {}

    public function dashboard(): JsonResponse
    {
        return ApiResponse::success(
            $this->adminPortalService->dashboard(),
        );
    }
}
