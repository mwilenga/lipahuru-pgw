<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GatewayErrorCode;
use App\Exceptions\GatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MerchantLoginRequest;
use App\Http\Requests\Api\V1\MerchantRegisterRequest;
use App\Http\Resources\MerchantResource;
use App\Models\MerchantUser;
use App\Services\Merchant\MerchantOnboardingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class MerchantAuthController extends Controller
{
    public function __construct(
        private readonly MerchantOnboardingService $merchantOnboardingService,
    ) {}

    public function register(MerchantRegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $password = $data['password'];
        unset($data['password'], $data['password_confirmation']);

        $result = $this->merchantOnboardingService->onboard($data);

        MerchantUser::query()->create([
            'merchant_id' => $result['merchant']->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $password,
            'role' => 'owner',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return ApiResponse::success([
            'merchant' => new MerchantResource($result['merchant']),
            'clientId' => $result['client_id'],
            'clientSecret' => $result['client_secret'],
        ], 'Merchant registered successfully. Store credentials securely — they are shown once.');
    }

    public function login(MerchantLoginRequest $request): JsonResponse
    {
        $user = MerchantUser::query()
            ->where('email', $request->validated('email'))
            ->where('is_active', true)
            ->first();

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            throw new GatewayException(GatewayErrorCode::AuthenticationFailed, 'Invalid credentials.', httpStatus: 401);
        }

        $deviceName = $request->validated('device_name') ?? 'merchant-dashboard';
        $token = $user->createToken($deviceName)->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'tokenType' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'merchantId' => $user->merchant_id,
            ],
        ], 'Login successful.');
    }
}
