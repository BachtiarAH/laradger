<?php

test('the openapi specification is served as yaml', function () {
    $this->get('/api/docs')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/yaml; charset=utf-8')
        ->assertSee('openapi: 3.1.0', false)
        ->assertSee('Ledgify API');
});

test('the openapi specification is served as json when requested', function () {
    $this->getJson('/api/docs')
        ->assertOk()
        ->assertJsonPath('info.title', 'Ledgify API')
        ->assertJsonPath('openapi', '3.1.0');
});
