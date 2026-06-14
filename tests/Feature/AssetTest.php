<?php

it('serves the bundled stylesheet without authentication', function () {
    $response = $this->get(route('vigilance.assets.css'));

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/css');
});
