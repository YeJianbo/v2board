<?php

namespace Tests\Concerns;

use App\Services\AgentHttpTransport;
use Illuminate\Support\Facades\Http;

trait FakesAgentTransport
{
    protected function fakeAgentTransport(): void
    {
        $transport = \Mockery::mock(AgentHttpTransport::class);
        $transport->shouldReceive('post')->andReturnUsing(fn ($url, $key, $body) => Http::withToken($key)->post($url, $body));
        $this->app->instance(AgentHttpTransport::class, $transport);
    }
}
