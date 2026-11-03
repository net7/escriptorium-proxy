<?php

use App\Services\eScriptoriumService;
use Illuminate\Support\Facades\Http;
use WebSocket\Client;

test('creates a real WebSocket client with the configured timeout', function (?int $timeout, int $expectedTimeout) {
    Http::preventStrayRequests();
    config([
        'escriptorium.api.base_url' => 'https://escriptorium.example.test',
        'escriptorium.websocket.timeout' => 45,
    ]);
    $service = Mockery::mock(eScriptoriumService::class)->makePartial();
    $service->__construct();
    $service->shouldReceive('getSessionCookie')->once()->andReturn('test-session');

    $client = $service->createWebSocketClient($timeout);

    expect($client)->toBeInstanceOf(Client::class)
        ->and($client->getTimeout())->toBe($expectedTimeout)
        ->and($client->isConnected())->toBeFalse();
    Http::assertNothingSent();
})->with([
    'configured default' => [null, 45],
    'explicit timeout' => [12, 12],
]);
