<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Request;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    public function test_it_trusts_local_proxy_by_default(): void
    {
        \Illuminate\Support\Facades\Route::get('/_test_ip', function (\Illuminate\Http\Request $req) {
            return $req->ip();
        });

        // Simulate a request coming from local proxy (127.0.0.1) forwarding another IP
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.50',
        ])->get('/_test_ip');

        $this->assertEquals('192.168.1.50', $response->content());
    }

    public function test_it_does_not_trust_arbitrary_proxy_by_default(): void
    {
        \Illuminate\Support\Facades\Route::get('/_test_ip', function (\Illuminate\Http\Request $req) {
            return $req->ip();
        });

        // Simulate a request coming from untrusted proxy (10.0.0.1) forwarding another IP
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.50',
        ])->get('/_test_ip');

        $this->assertEquals('10.0.0.1', $response->content());
    }
}
