<?php

use Illuminate\Support\Facades\DB;

it('uses the canonical isolated database identity', function () {
    $connection = DB::connection();

    expect($connection->getDriverName())->toBe('sqlite')
        ->and($connection->getDatabaseName())->toBe(':memory:');
});
