<?php

use App\Contexts\ApiContext;
use App\Facades\eScriptorium;
use App\Http\Controllers\API\v1\eScriptoriumController;
use App\Http\Requests\eScriptorium\NewModelRequest;
use App\Services\eScriptoriumService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['escriptorium.api.base_url' => 'http://escriptorium.test']);
    ApiContext::setDirectToken('model-test-token');
    Http::preventStrayRequests();
});

afterEach(fn () => ApiContext::reset());

test('uploads legacy and safetensors models with the correct job and user token', function (string $extension, string $task, string $job) {
    if ($extension === 'safetensors') {
        $metadata = json_encode(['model-id' => ['_tasks' => [$task], '_model' => 'TorchVGSLModel']]);
        $header = json_encode(['__metadata__' => ['kraken_meta' => $metadata]]);
        $content = pack('P', strlen($header)).$header;
    } else {
        $content = "\x00\x01".'{"model_type": "'.$task.'"}';
    }
    $file = UploadedFile::fake()->createWithContent("model.{$extension}", $content);
    Http::fake(['http://escriptorium.test/api/models/' => Http::response(['pk' => 9], 201)]);

    expect((new eScriptoriumService)->newModel('My model', $file))->toBe(['pk' => 9]);
    Http::assertSent(fn (Request $request) => collect($request->data())->firstWhere('name', 'job')['contents'] === $job
        && collect($request->data())->firstWhere('name', 'name')['contents'] === 'My model'
        && $request->hasFile('file', $content, "model.{$extension}")
        && $request->hasHeader('Authorization', 'Token model-test-token'));
})->with([
    'legacy recognition' => ['mlmodel', 'recognition', 'Recognize'],
    'legacy segmentation' => ['mlmodel', 'segmentation', 'Segment'],
    'safetensors recognition' => ['safetensors', 'recognition', 'Recognize'],
    'safetensors segmentation' => ['safetensors', 'segmentation', 'Segment'],
]);

test('rejects a truncated safetensors header before contacting eScriptorium', function () {
    $file = UploadedFile::fake()->createWithContent('model.safetensors', pack('P', 1024).'{}');

    expect(fn () => (new eScriptoriumService)->newModel('Invalid', $file))
        ->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

test('preserves the model format when uploading through the browser', function (string $extension) {
    ApiContext::setServiceAuth();
    $file = UploadedFile::fake()->createWithContent("original.{$extension}", 'model contents');
    $csrf = '<input name="csrfmiddlewaretoken" value="test-csrf">';
    Http::fake([
        'http://escriptorium.test/login/' => Http::response($csrf),
        'http://escriptorium.test/models/new/' => Http::sequence()
            ->push($csrf)
            ->push('', 302, ['Location' => '/models/']),
    ]);

    expect((new eScriptoriumService)->newModelViaBrowser("Renamed.{$extension}", $file))->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'http://escriptorium.test/models/new/'
        && collect($request->data())->firstWhere('name', 'name')['contents'] === 'Renamed'
        && $request->hasFile('file', null, "Renamed.{$extension}"));
})->with(['mlmodel', 'safetensors']);

test('does not treat a browser upload form with validation errors as success', function () {
    ApiContext::setServiceAuth();
    $file = UploadedFile::fake()->createWithContent('invalid.mlmodel', 'invalid model');
    $csrf = '<input name="csrfmiddlewaretoken" value="test-csrf">';
    Http::fake([
        'http://escriptorium.test/login/' => Http::response($csrf),
        'http://escriptorium.test/models/new/' => Http::sequence()
            ->push($csrf)
            ->push('<ul class="errorlist"><li>The provided model could not be loaded.</li></ul>'),
    ]);

    expect(fn () => (new eScriptoriumService)->newModelViaBrowser(null, $file))
        ->toThrow(RuntimeException::class);
});

test('uses the API for direct mode model uploads so ownership stays with the caller', function () {
    $file = UploadedFile::fake()->create('model.mlmodel');
    $request = Mockery::mock(NewModelRequest::class);
    $request->shouldReceive('validated')->with('name')->andReturn('My model');
    $request->shouldReceive('validated')->with('file')->andReturn($file);
    eScriptorium::shouldReceive('models')->once()->andReturn([]);
    eScriptorium::shouldReceive('newModel')->with('My model', $file)->once()->andReturn(['pk' => 9]);
    eScriptorium::shouldNotReceive('newModelViaBrowser');

    expect((new eScriptoriumController)->newModel($request)->getStatusCode())->toBe(201);
});
