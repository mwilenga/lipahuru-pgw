<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsPreflightTest extends TestCase
{
    public function test_admin_login_preflight_allows_configured_portal_origin(): void
    {
        config([
            'cors.allowed_origins' => ['https://lipahuru-portal.gotiketi.co.tz'],
        ]);

        $response = $this->call(
            'OPTIONS',
            '/api/admin/v1/login',
            server: [
                'HTTP_ORIGIN' => 'https://lipahuru-portal.gotiketi.co.tz',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
            ],
        );

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', 'https://lipahuru-portal.gotiketi.co.tz');
        $response->assertHeader('Access-Control-Allow-Methods');
    }

    public function test_admin_login_preflight_allows_any_origin_when_wildcard_configured(): void
    {
        config([
            'cors.allowed_origins' => ['*'],
        ]);

        $response = $this->call(
            'OPTIONS',
            '/api/admin/v1/login',
            server: [
                'HTTP_ORIGIN' => 'https://lipahuru-portal.gotiketi.co.tz',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
            ],
        );

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', '*');
    }
}
