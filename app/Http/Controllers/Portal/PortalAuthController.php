<?php

namespace App\Http\Controllers\Portal;

use App\Enums\GatewayErrorCode;
use App\Exceptions\GatewayException;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\MerchantUser;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PortalAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $email = $request->input('email');
        $password = $request->input('password');
        $deviceName = $request->input('device_name', 'portal');

        $admin = AdminUser::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->first();

        if ($admin !== null) {
            if (! Hash::check($password, $admin->password)) {
                throw new GatewayException(GatewayErrorCode::AuthenticationFailed, 'Invalid credentials.', httpStatus: 401);
            }

            return ApiResponse::success([
                'token' => $admin->createToken($deviceName)->plainTextToken,
                'tokenType' => 'Bearer',
                'role' => 'admin',
                'user' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => $admin->role,
                ],
            ], 'Login successful.');
        }

        $merchantUser = MerchantUser::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->first();

        if ($merchantUser === null || ! Hash::check($password, $merchantUser->password)) {
            throw new GatewayException(GatewayErrorCode::AuthenticationFailed, 'Invalid credentials.', httpStatus: 401);
        }

        return ApiResponse::success([
            'token' => $merchantUser->createToken($deviceName)->plainTextToken,
            'tokenType' => 'Bearer',
            'role' => 'merchant',
            'user' => [
                'id' => $merchantUser->id,
                'name' => $merchantUser->name,
                'email' => $merchantUser->email,
                'role' => $merchantUser->role,
                'merchantId' => $merchantUser->merchant_id,
            ],
        ], 'Login successful.');
    }
}
