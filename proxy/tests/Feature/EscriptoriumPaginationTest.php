<?php

use App\Contexts\ApiContext;
use App\Services\eScriptoriumService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['escriptorium.api.base_url' => 'http://escriptorium.test']);
    ApiContext::setDirectToken('pagination-test-token');
    Http::preventStrayRequests();
});

afterEach(fn () => ApiContext::reset());

test('collects every page without changing endpoint authentication or filters', function (string $method, array $arguments, string $path, array $filters, bool $envelope) {
    $first = ['pk' => 11, 'name' => 'Target'];
    $second = ['pk' => 12, 'name' => 'Target'];

    Http::fake([
        'http://escriptorium.test/*' => Http::sequence()
            ->push(['count' => 2, 'next' => "https://public.example.test/{$path}?page=2", 'previous' => null, 'results' => [$first]])
            ->push(['count' => 2, 'next' => null, 'previous' => "https://public.example.test/{$path}", 'results' => [$second]]),
    ]);

    $result = (new eScriptoriumService)->{$method}(...$arguments);

    expect($envelope ? $result['results'] : $result)->toBe([$first, $second]);
    if ($envelope) {
        expect($result)->toMatchArray(['count' => 2, 'next' => null, 'previous' => null]);
    }

    Http::assertSentCount(2);
    Http::assertSent(function (Request $request) use ($path, $filters) {
        return strtok($request->url(), '?') === "http://escriptorium.test/{$path}"
            && $request->hasHeader('Authorization', 'Token pagination-test-token')
            && (int) $request['page'] === 2
            && collect($filters)->every(fn ($value, $key) => (string) $request[$key] === (string) $value);
    });
})->with([
    'models' => ['models', [], 'api/models/', [], false],
    'scripts' => ['scripts', [], 'api/scripts/', [], false],
    'document parts' => ['getDocumentParts', ['42'], 'api/documents/42/parts/', ['ordering' => 'order'], true],
    'task polling' => ['tasks', ['42'], 'api/tasks/', ['document' => '42'], true],
    'project lookup' => ['getProjects', ['Target'], 'api/projects/', ['name' => 'Target'], false],
    'document lookup' => ['getDocuments', [9, 'Target'], 'api/documents/', ['name' => 'Target', 'project' => 9], false],
]);

test('finds an exact project name on a later page', function () {
    Http::fakeSequence()
        ->push(['count' => 2, 'next' => '?page=2', 'results' => [['id' => 1, 'name' => 'Target extended']]])
        ->push(['count' => 2, 'next' => null, 'results' => [['id' => 2, 'name' => 'Target']]]);

    expect((new eScriptoriumService)->getProjects('Target'))->toBe([['id' => 2, 'name' => 'Target']]);
});

test('does not report partial task results when a later page fails', function () {
    Http::fakeSequence()
        ->push(['count' => 2, 'next' => '?page=2', 'results' => [['pk' => 1, 'workflow_state' => 3]]])
        ->push(['detail' => 'Unavailable'], 503);

    expect(fn () => (new eScriptoriumService)->tasks('42'))->toThrow(RuntimeException::class);
});

test('rejects a pagination loop instead of polling indefinitely', function () {
    Http::fakeSequence()
        ->push(['count' => 3, 'next' => '?page=2', 'results' => [['pk' => 1]]])
        ->push(['count' => 3, 'next' => '?page=2', 'results' => [['pk' => 2]]]);

    expect(fn () => (new eScriptoriumService)->getDocumentParts('42'))->toThrow(RuntimeException::class);
});
