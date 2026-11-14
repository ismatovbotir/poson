<?php

namespace Tests;

use App\Services\Ai\ChatModel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeChatModel;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Plan 30 (AI-25): no test ever reaches the Anthropic API; tests that need answers rebind with FakeChatModel::bind().
        $this->app->instance(ChatModel::class, new FakeChatModel);
    }
}
