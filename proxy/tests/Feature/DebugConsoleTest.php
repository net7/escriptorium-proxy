<?php

use Inertia\Testing\AssertableInertia;

test('renders the debug console with the Inertia initial page in staging', function () {
    $this->app['env'] = 'staging';
    $this->withoutVite();

    $this->get('/debug')
        ->assertOk()
        ->assertSee('type="application/json"', false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Debug')
            ->has('endpoints')
            ->where('endpoints.0.path', '/api/v1/up')
        );
});

test('serves the debug console props on an Inertia visit', function () {
    $this->app['env'] = 'staging';
    $this->withoutVite();
    $version = $this->get('/debug')->viewData('page')['version'];

    $this->get('/debug', [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-Inertia-Version' => $version,
    ])
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Debug')
        ->assertJsonPath('props.endpoints.0.path', '/api/v1/up');
});

test('keeps the debug console unavailable in production', function () {
    $this->app['env'] = 'production';

    $this->get('/debug')->assertNotFound();
});
