<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

/** The demo dataset (accounts with a published password) can never be seeded where dev mode is off. */
class DemoSeedGuardTest extends TestCase
{
    public function test_seeder_refuses_to_run_when_dev_mode_is_off(): void
    {
        config(['raha.dev_accounts' => false]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dedicated production seeder');
        (new DatabaseSeeder)->run();
    }

    public function test_production_env_cannot_turn_dev_mode_on_with_the_flag(): void
    {
        $this->assertFalse(\App\Support\Security\DevMode::enabled('production', true));
    }
}
