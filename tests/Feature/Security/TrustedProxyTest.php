<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/__trusted-proxy-check', function (Request $request) {
        return response()->json([
            'client_ip' => $request->ip(),
            'host' => $request->getHost(),
            'scheme' => $request->getScheme(),
            'secure' => $request->isSecure(),
            'url' => url('/secure-area'),
        ]);
    });
});

test('trusted proxy forwards client scheme host and ip', function (): void {
    config(['trustedproxy.proxies' => ['10.10.0.5']]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.5'])
        ->withHeaders([
            'Host' => 'internal.test',
            'X-Forwarded-For' => '198.51.100.23, 10.10.0.5',
            'X-Forwarded-Host' => 'nomina.example.com',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
        ])
        ->getJson('/__trusted-proxy-check')
        ->assertOk()
        ->assertJson([
            'client_ip' => '198.51.100.23',
            'host' => 'nomina.example.com',
            'scheme' => 'https',
            'secure' => true,
            'url' => 'https://nomina.example.com/secure-area',
        ]);
});

test('untrusted clients cannot spoof forwarded host scheme or ip', function (): void {
    config(['trustedproxy.proxies' => ['10.10.0.5']]);

    $this->withServerVariables([
            'HTTP_HOST' => 'internal.test',
            'REMOTE_ADDR' => '203.0.113.44',
            'SERVER_NAME' => 'internal.test',
        ])
        ->withHeaders([
            'X-Forwarded-For' => '198.51.100.23',
            'X-Forwarded-Host' => 'evil.example.com',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
        ])
        ->getJson('/__trusted-proxy-check')
        ->assertOk()
        ->assertJsonPath('client_ip', '203.0.113.44')
        ->assertJsonPath('scheme', 'http')
        ->assertJsonPath('secure', false)
        ->assertJsonMissing(['host' => 'evil.example.com'])
        ->assertJsonMissing(['url' => 'https://evil.example.com/secure-area']);
});

test('local requests ignore forwarded headers when no proxies are configured', function (): void {
    config(['trustedproxy.proxies' => null]);

    $this->withServerVariables([
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_NAME' => 'localhost',
        ])
        ->withHeaders([
            'X-Forwarded-For' => '198.51.100.23',
            'X-Forwarded-Host' => 'nomina.example.com',
            'X-Forwarded-Proto' => 'https',
        ])
        ->getJson('/__trusted-proxy-check')
        ->assertOk()
        ->assertJsonPath('client_ip', '127.0.0.1')
        ->assertJsonPath('scheme', 'http')
        ->assertJsonPath('secure', false)
        ->assertJsonMissing(['host' => 'nomina.example.com']);
});
