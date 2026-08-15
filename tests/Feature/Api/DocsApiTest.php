<?php

test('the openapi specification is rendered inline as text by default', function () {
    $this->get('/api/docs')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertHeaderMissing('Content-Disposition')
        ->assertSee('openapi: 3.1.0', false)
        ->assertSee('Ledgify API');
});

test('the openapi specification is downloaded when the download param is present', function () {
    $this->get('/api/docs?download=1')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/yaml; charset=utf-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="openapi.yaml"')
        ->assertSee('openapi: 3.1.0', false);
});

test('the openapi specification is served as json when requested', function () {
    $this->getJson('/api/docs')
        ->assertOk()
        ->assertJsonPath('info.title', 'Ledgify API')
        ->assertJsonPath('openapi', '3.1.0');
});
