<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OAuthTokenRequest;
use App\Services\Auth\OAuthTokenService;
use Illuminate\Http\JsonResponse;

class OAuthTokenController extends Controller
{
    public function __construct(
        private readonly OAuthTokenService $oauthTokenService,
    ) {}

    public function issue(OAuthTokenRequest $request): JsonResponse
    {
        info($request->all());
        $token = $this->oauthTokenService->issueClientCredentialsToken(
            $request->validated('client_id'),
            $request->validated('client_secret'),
        );

        return response()->json($token);
    }
}
