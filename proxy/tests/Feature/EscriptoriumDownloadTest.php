<?php

use App\Contexts\ApiContext;
use App\Enums\ExportFormatEnum;
use App\Jobs\eScriptoriumDownloadJob;
use App\Models\Transcription;
use App\Services\eScriptoriumServiceDataManager;
use Illuminate\Support\Facades\Http;
use WebSocket\Client;
use WebSocket\Message\Text;

beforeEach(function () {
    config(['escriptorium.websocket.timeout' => 2]);
    Http::preventStrayRequests();
});

afterEach(fn () => ApiContext::reset());

test('waits for the supplied export link after the document completion event', function (bool $directMode) {
    $directMode ? ApiContext::setDirectToken('export-test-token') : ApiContext::setServiceAuth();
    $transcription = new Transcription([
        'export_format' => ExportFormatEnum::TeiXml,
        'service_data' => ['escriptorium' => ['document' => ['pk' => 42, 'name' => 'My Document']]],
    ]);
    $job = new eScriptoriumDownloadJob($transcription);
    (new ReflectionProperty($job, 'dataManager'))->setValue($job, eScriptoriumServiceDataManager::for($transcription));
    $link = '/media/users/7/export_doc42_my_document_teixml_20260909123456.zip';
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('receive')->andReturn(
        new Text(json_encode(['type' => 'event', 'name' => 'export:done', 'data' => ['id' => 42]])),
        new Text(json_encode(['type' => 'message', 'text' => 'Export done!', 'links' => [['src' => $link]]])),
    );

    expect((new ReflectionMethod($job, 'waitForDownloadLink'))->invoke($job, $client))->toBe($link);
})->with(['service mode' => false, 'direct mode' => true]);

test('selects this document and format regardless of notification language', function () {
    ApiContext::setDirectToken('export-test-token');
    $transcription = new Transcription([
        'export_format' => ExportFormatEnum::Text,
        'service_data' => ['escriptorium' => ['document' => ['pk' => 42, 'name' => 'My Document']]],
    ]);
    $job = new eScriptoriumDownloadJob($transcription);
    (new ReflectionProperty($job, 'dataManager'))->setValue($job, eScriptoriumServiceDataManager::for($transcription));
    $link = '/media/users/7/export_doc42_my_document_text_20260909123456.txt';
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('receive')->andReturn(
        new Text(json_encode(['type' => 'message', 'text' => 'Export done!', 'links' => [['src' => '/media/users/7/export_doc43_other_text_20260909123456.txt']]])),
        new Text(json_encode(['type' => 'message', 'text' => 'Export done!', 'links' => [['src' => '/media/users/7/export_doc42_my_document_alto_20260909123456.zip']]])),
        new Text(json_encode(['type' => 'message', 'text' => 'Export terminé !', 'links' => [['src' => $link]]])),
    );

    expect((new ReflectionMethod($job, 'waitForDownloadLink'))->invoke($job, $client))->toBe($link);
});

test('propagates export errors from eScriptorium', function (string $event) {
    ApiContext::setServiceAuth();
    $transcription = new Transcription(['service_data' => ['escriptorium' => ['document' => ['pk' => 42]]]]);
    $job = new eScriptoriumDownloadJob($transcription);
    (new ReflectionProperty($job, 'dataManager'))->setValue($job, eScriptoriumServiceDataManager::for($transcription));
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('receive')->andReturn(new Text(json_encode([
        'type' => 'event', 'name' => $event, 'data' => ['id' => 42, 'reason' => 'Export failed upstream'],
    ])));

    expect(fn () => (new ReflectionMethod($job, 'waitForDownloadLink'))->invoke($job, $client))
        ->toThrow(RuntimeException::class, 'Export failed upstream');
})->with(['export:error', 'import:error']);
