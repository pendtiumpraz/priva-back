<?php

namespace Tests\Feature\Lms;

use Tests\TestCase;

class PublicVerifyStubTest extends TestCase
{
    public function test_public_verify_route_is_unauthenticated_and_returns_501(): void
    {
        $this->get('/verify/CERT-DOES-NOT-EXIST')->assertStatus(501);
    }
}
