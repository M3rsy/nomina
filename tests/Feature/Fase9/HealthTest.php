<?php

test('/health returns 200 with status ok', function () {
    $response = $this->get('/health');

    $response->assertStatus(200);
    $response->assertJson([
        'status' => 'ok',
    ]);

    $data = $response->json('checks');
    expect($data['database'])->toBeTrue();
    expect($data['storage'])->toBeTrue();
    expect($data['cache'])->toBeTrue();
});
