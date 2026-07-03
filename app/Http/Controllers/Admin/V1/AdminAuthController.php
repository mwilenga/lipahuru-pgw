<?php

namespace App\Http\Controllers\Admin\V1;

use App\Enums\GatewayErrorCode;
use App\Exceptions\GatewayException;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $admin = AdminUser::query()
            ->where('email', $request->input('email'))
            ->where('is_active', true)
            ->first();

        if ($admin === null || ! Hash::check($request->input('password'), $admin->password)) {
            throw new GatewayException(GatewayErrorCode::AuthenticationFailed, 'Invalid credentials.', httpStatus: 401);
        }

        $token = $admin->createToken($request->input('device_name', 'admin-dashboard'))->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'tokenType' => 'Bearer',
            'user' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
            ],
        ], 'Login successful.');
    }
}
