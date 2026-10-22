<?php

namespace Tests\Feature;

use App\Http\Middleware\UseCartSessionLocks;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** Configuration/pipeline checks without migrations, seeders or database access. */
class WebSessionLockingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Uncached SQLite :memory: required.');
        }
        Route::middleware('web')->get('/__session-lock/probe', fn () => response()->json([
            'block' => config('session.block'), 'store' => config('session.block_store'),
        ]))->name('audit.session-probe');
    }

    public function test_lock_configuration_precedes_session_reads_on_all_shared_web_routes(): void
    {
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        foreach ([['GET', '/'], ['GET', '/produits'], ['GET', '/boutiques/alpha/produits'],
            ['GET', '/boutiques/alpha/panier'], ['GET', '/shops/alpha/cart'], ['POST', '/shops/alpha/cart/switch'],
            ['GET', '/client/connexion'], ['GET', '/client/compte'], ['POST', '/client/deconnexion'],
            ['GET', '/login'], ['POST', '/logout'], ['GET', '/admin/login']] as [$method, $path]) {
            $route = app('router')->getRoutes()->match(Request::create($path, $method));
            $middleware = app('router')->gatherRouteMiddleware($route);
            $before = array_search(UseCartSessionLocks::class, $middleware, true);
            $session = array_search(StartSession::class, $middleware, true);
            $this->assertNotFalse($before, $path);
            $this->assertNotFalse($session, $path);
            $this->assertLessThan($session, $before, $path);
        }
        $this->get('/__session-lock/probe')->assertOk()->assertJsonPath('block', true)->assertJsonPath('store', 'file');
    }

    public function test_invalid_cache_store_session_driver_or_timeouts_fail_closed_on_html(): void
    {
        foreach ([['cache.stores.file.driver', 'array'], ['session.driver', 'cookie'],
            ['session.block_lock_seconds', 0], ['session.block_wait_seconds', -1]] as [$key, $value]) {
            $original = config($key); config([$key => $value]);
            $this->get('/__session-lock/probe')->assertStatus(503);
            config([$key => $original]);
        }
    }

    public function test_shared_redis_store_can_be_selected_without_default_database_fallback(): void
    {
        config(['session.block_store' => 'redis']);
        // Configuration only: never contact Redis or the database in this test.
        (new UseCartSessionLocks())->handle(Request::create('/'), fn () => new Response('ok'));
        $this->assertTrue(config('session.block'));
        $this->assertSame('redis', config('session.block_store'));
    }

    public function test_demo_always_uses_its_existing_isolated_file_store(): void
    {
        $this->app->detectEnvironment(fn () => 'demo');
        config(['cache' => require base_path('bootstrap/demo-cache.php'), 'session.block_store' => 'redis']);
        (new UseCartSessionLocks())->handle(Request::create('/'), fn () => new Response('ok'));
        $this->assertSame('demo_limiter', config('session.block_store'));
        $this->assertTrue(config('session.block'));
        $this->assertSame('file', config('cache.stores.demo_limiter.driver'));
    }
}
