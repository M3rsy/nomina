<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\Concerns\DatabaseMigrationsSafely;
use Tests\Concerns\RefreshDatabaseSafely;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(
    TestCase::class,
    RefreshDatabaseSafely::class,
)->in('Feature');

uses(TestCase::class, DatabaseMigrationsSafely::class)->in('PostgreSQL');

uses()->afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
})->in('Feature', 'PostgreSQL', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
