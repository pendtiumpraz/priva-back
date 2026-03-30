<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Organization;

class ApiFeatureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test whether the API correctly rejects unauthenticated users.
     */
    public function test_api_rejects_unauthenticated_dashboard_requests(): void
    {
        $response = $this->getJson('/api/dashboard/stats');

        $response->assertStatus(401);
    }

    /**
     * Test successful authentication and reading data.
     */
    public function test_api_allows_authenticated_users(): void
    {
        // Requires DatabaseTransactions or RefreshDatabase, but we just use an existing user for MVP read
        $user = User::first();

        if (! $user) {
            $this->markTestSkipped('No user available in database.');
        }

        $response = $this->actingAs($user)->getJson('/api/auth/me');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         'id', 'name', 'email', 'role'
                     ]
                 ]);
    }
}
