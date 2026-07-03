<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\AdminReportRequest;
use App\Services\Report\ReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AdminReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reportService,
    ) {}

    public function merchantSummary(AdminReportRequest $request): JsonResponse
    {
        $merchantId = (int) $request->validated('merchant_id');
        $from = isset($request->validated()['from']) ? Carbon::parse($request->validated('from')) : null;
        $to = isset($request->validated()['to']) ? Carbon::parse($request->validated('to')) : null;

        $summary = $this->reportService->getMerchantSummary($merchantId, $from, $to);

        return ApiResponse::success($summary);
    }
}
