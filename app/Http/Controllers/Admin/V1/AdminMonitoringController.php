<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\WebhookDeliveryResource;
use App\Models\AuditLog;
use App\Models\WebhookDelivery;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminMonitoringController extends Controller
{
    public function health(): JsonResponse
    {
        $checks = [
            'database' => false,
            'cache' => false,
        ];

        try {
            DB::connection()->getPdo();
            $checks['database'] = true;
        } catch (\Throwable) {
            //
        }

        try {
            cache()->put('gateway:health', true, 10);
            $checks['cache'] = cache()->get('gateway:health') === true;
        } catch (\Throwable) {
            //
        }

        $healthy = ! in_array(false, $checks, true);

        return ApiResponse::success([
            'status' => $healthy ? 'healthy' : 'degraded',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function webhookLogs(Request $request): JsonResponse
    {
        $paginator = WebhookDelivery::query()
            ->with('transaction')
            ->latest()
            ->paginate((int) $request->query('perPage', 25));

        return ApiResponse::success([
            'deliveries' => WebhookDeliveryResource::collection($paginator->items()),
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $paginator = AuditLog::query()
            ->latest('created_at')
            ->paginate((int) $request->query('perPage', 25));

        return ApiResponse::success([
            'logs' => AuditLogResource::collection($paginator->items()),
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }
}
