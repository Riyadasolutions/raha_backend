<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_twice_changes_nothing_and_keeps_foreign_records(): void
    {
        $this->seed(DatabaseSeeder::class);
        DB::table('settings')->where('key', 'language')->update(['value' => json_encode(['defaultLang' => 'en'])]);
        $counts = fn () => [DB::table('users')->count(), DB::table('cases')->count(), DB::table('roles')->count()];
        $before = $counts();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, $counts());
        $this->assertStringContainsString('en', DB::table('settings')->where('key', 'language')->value('value'));
    }
}
