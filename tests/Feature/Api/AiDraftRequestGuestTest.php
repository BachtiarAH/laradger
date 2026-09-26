<?php

use App\Models\Tenant;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->base = "/api/v1/{$this->tenant->slug}";
});

test('guests cannot list or submit drafting requests', function () {
    $this->getJson("{$this->base}/ai/draft-requests")->assertUnauthorized();
    $this->postJson("{$this->base}/ai/draft-requests", ['prompt' => 'record 4.50 of coffee'])
        ->assertUnauthorized();
});
