<?php

test('the application returns a successful response', function (): void {
    $response = $this->get(route('companies.index'));

    $response->assertStatus(200);
});
