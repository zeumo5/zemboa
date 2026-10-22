<?php

namespace Tests\Unit;

use Fiber;
use Illuminate\Cache\Lock;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use SessionHandlerInterface;
use Symfony\Component\HttpFoundation\Response;

/** Native StartSession pipeline; only storage and scheduling are memory doubles. */
class SessionConcurrencyTest extends TestCase
{
    private MemorySessionState $state;
    private MemorySessionLocks $locks;

    protected function setUp(): void
    {
        $this->state = new MemorySessionState();
        $this->state->payloads[str_repeat('a', 40)] = json_encode(['zemboa' => ['cart' => ['store_id' => 1, 'items' => [11 => 2]]]]);
        $this->locks = new MemorySessionLocks();
    }

    private function request(string $name, callable $action, bool $global = true, string $id = 'a', int $wait = 10): Fiber
    {
        $container = new Container();
        $container->instance('config', new Repository(['session' => [
            'driver' => 'memory_audit', 'cookie' => 'audit', 'serialization' => 'json',
            'encrypt' => false, 'lifetime' => 120, 'expire_on_close' => false,
            'lottery' => [0, 1], 'block' => $global, 'block_store' => 'audit',
            'block_lock_seconds' => 120, 'block_wait_seconds' => $wait,
        ]]));
        $manager = new SessionManager($container);
        $state = $this->state;
        $manager->extend('memory_audit', fn () => new CountingMemorySessionHandler($state));
        $factory = new class($this->locks) {
            public function __construct(private MemorySessionLocks $locks) {}
            public function driver($name): MemorySessionLocks { return $this->locks; }
        };
        // Cookies are transport only; read/start/save/lock/release are unmodified Laravel.
        $middleware = new class($manager, fn () => $factory) extends StartSession {
            protected function addCookieToResponse(Response $response, Session $session) {}
        };
        $request = Request::create('/audit/'.$name, str_contains($name, 'html') ? 'GET' : 'POST');
        $request->cookies->set('audit', str_repeat($id, 40));
        $route = (new Route(['GET', 'POST'], '/audit/'.$name, fn () => null))->name($name);
        if (!$global && str_starts_with($name, 'json.')) $route->block(120, $wait);
        $request->setRouteResolver(fn () => $route);
        return new Fiber(fn () => $middleware->handle($request, function (Request $request) use ($action) {
            $action($request->session());
            return new Response('ok');
        }));
    }

    private function cart(): ?array
    {
        return json_decode($this->state->payloads[str_repeat('a', 40)], true)['zemboa']['cart'] ?? null;
    }

    public function test_original_bug_is_reproducible_when_only_json_routes_lock(): void
    {
        $html = $this->request('html.catalogue', fn (Session $session) => Fiber::suspend('html-read'), false);
        $json = $this->request('json.switch', fn (Session $session) => $session->forget('zemboa.cart'), false);
        $this->assertSame('html-read', $html->start());
        $json->start();
        $this->assertNull($this->cart());
        $html->resume();
        $this->assertSame([11 => 2], $this->cart()['items']);
    }

    public function test_html_started_first_blocks_abandonment_until_its_session_save(): void
    {
        $html = $this->request('html.catalogue', fn (Session $session) => Fiber::suspend('html-read'));
        $json = $this->request('json.switch', fn (Session $session) => $session->forget('zemboa.cart'));
        $html->start(); $reads = $this->state->reads;
        $this->assertSame('waiting-for-session', $json->start());
        $this->assertSame($reads, $this->state->reads, 'Waiting request must not read an old snapshot.');
        $html->resume(); $json->resume();
        $this->assertNull($this->cart());
        $this->assertTrue($html->isTerminated() && $json->isTerminated());
        $this->assertSame([], $this->locks->owners);
    }

    public function test_html_started_during_abandonment_reads_only_the_saved_empty_cart(): void
    {
        $json = $this->request('json.switch', function (Session $session) {
            $session->forget('zemboa.cart'); Fiber::suspend('before-save');
        });
        $seen = 'not-read';
        $html = $this->request('html.product', function (Session $session) use (&$seen) { $seen = $session->get('zemboa.cart'); });
        $json->start(); $this->assertSame('waiting-for-session', $html->start());
        $this->assertSame('not-read', $seen);
        $json->resume(); $html->resume();
        $this->assertNull($seen); $this->assertNull($this->cart());
    }

    public function test_overlapping_add_update_remove_and_switch_do_not_lose_confirmed_writes(): void
    {
        // Each operation reads the state AFTER acquiring Laravel's shared session lock.
        $add = $this->request('json.add', function (Session $session) {
            Fiber::suspend('add-read'); $session->put('zemboa.cart.items.22', 1);
        });
        $update = $this->request('json.update', fn (Session $s) => $s->put('zemboa.cart.items.11', 4));
        $remove = $this->request('json.remove', fn (Session $s) => $s->forget('zemboa.cart.items.22'));
        $switch = $this->request('json.switch', fn (Session $s) => $s->forget('zemboa.cart'));
        $add->start();
        foreach ([$update, $remove, $switch] as $request) $this->assertSame('waiting-for-session', $request->start());
        $add->resume(); $this->assertSame([11 => 2, 22 => 1], $this->cart()['items']);
        $update->resume(); $this->assertSame([11 => 4, 22 => 1], $this->cart()['items']);
        $remove->resume(); $this->assertSame([11 => 4], $this->cart()['items']);
        $switch->resume(); $this->assertNull($this->cart());
        $this->assertSame([], $this->locks->owners);
    }

    public function test_exception_releases_native_lock_and_next_request_can_finish(): void
    {
        $failing = $this->request('html.failure', function () { Fiber::suspend('before-error'); throw new \RuntimeException('controlled'); });
        $next = $this->request('json.update', fn (Session $s) => $s->put('zemboa.cart.items.11', 3));
        $failing->start(); $this->assertSame('waiting-for-session', $next->start());
        try { $failing->resume(); $this->fail('Expected controlled error'); }
        catch (\RuntimeException $error) { $this->assertSame('controlled', $error->getMessage()); }
        $next->resume();
        $this->assertSame(3, $this->cart()['items'][11]);
        $this->assertSame([], $this->locks->owners);
    }

    public function test_timeout_never_reads_or_saves_and_cannot_release_another_owner_lock(): void
    {
        $html = $this->request('html.long', fn () => Fiber::suspend('holding'));
        $timedOut = $this->request('json.update', fn (Session $s) => $s->forget('zemboa.cart'), wait: 0);
        $html->start(); $reads = $this->state->reads; $writes = $this->state->writes;
        try { $timedOut->start(); $this->fail('Expected timeout'); }
        catch (LockTimeoutException) { $this->assertSame($reads, $this->state->reads); }
        $this->assertSame($writes, $this->state->writes);
        $this->assertCount(1, $this->locks->owners);
        $html->resume(); $this->assertSame([], $this->locks->owners);
    }

    public function test_different_sessions_are_not_blocked_by_each_other(): void
    {
        $first = $this->request('html.long', fn () => Fiber::suspend('holding'));
        $independent = $this->request('html.other-user', fn (Session $s) => $s->put('marker', true), id: 'b');
        $first->start(); $independent->start();
        $this->assertTrue($independent->isTerminated());
        $this->assertTrue($first->isSuspended());
        $first->resume(); $this->assertSame([], $this->locks->owners);
    }

    public function test_uncontended_burst_keeps_all_writes_without_a_global_user_mutex(): void
    {
        $started = hrtime(true);
        for ($i = 0; $i < 100; $i++) {
            $request = $this->request('html.lightweight', fn (Session $s) => $s->put('counter', $s->get('counter', 0) + 1));
            $request->start();
            $this->assertTrue($request->isTerminated());
        }
        $this->assertSame(100, json_decode($this->state->payloads[str_repeat('a', 40)], true)['counter']);
        $this->assertSame([], $this->locks->owners);
        // Coarse guard for accidental sleeps/deadlocks, not a production load benchmark.
        $this->assertLessThan(5_000, (hrtime(true) - $started) / 1_000_000);
    }
}

final class MemorySessionState
{
    public array $payloads = [];
    public int $reads = 0;
    public int $writes = 0;
}

final class CountingMemorySessionHandler implements SessionHandlerInterface
{
    public function __construct(private MemorySessionState $state) {}
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { $this->state->reads++; return $this->state->payloads[$id] ?? ''; }
    public function write(string $id, string $data): bool { $this->state->writes++; $this->state->payloads[$id] = $data; return true; }
    public function destroy(string $id): bool { unset($this->state->payloads[$id]); return true; }
    public function gc(int $max_lifetime): int|false { return 0; }
}

final class MemorySessionLocks
{
    public array $owners = [];
    public function lock($name, $seconds): Lock
    {
        return new class($name, $seconds, $this) extends Lock {
            public function __construct($name, $seconds, private MemorySessionLocks $state) { parent::__construct($name, $seconds); }
            public function acquire() { if (isset($this->state->owners[$this->name])) return false; $this->state->owners[$this->name] = $this->owner; return true; }
            public function release() { if (!$this->isOwnedByCurrentProcess()) return false; unset($this->state->owners[$this->name]); return true; }
            protected function getCurrentOwner() { return $this->state->owners[$this->name] ?? null; }
            public function forceRelease() { unset($this->state->owners[$this->name]); }
            public function block($seconds, $callback = null) {
                while (!$this->acquire()) {
                    if ($seconds === 0) throw new LockTimeoutException();
                    Fiber::suspend('waiting-for-session');
                }
                return true;
            }
        };
    }
}
