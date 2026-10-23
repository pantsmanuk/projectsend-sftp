<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use ProjectSendSftp\SftpTransferServiceProvider;

// Local test application only. This synthetic identity must never be registered
// by the package provider or used in the real ProjectSend application.
final class SftpFixtureIdentity
{
    public function handle(Request $request, Closure $next): mixed
    {
        $role = $request->header('X-Sftp-Test-Role', 'admin');
        if ($role !== 'guest') {
            $user = new class extends User
            {
                public string $testRole;

                public function isStaff(): bool { return $this->testRole !== 'client'; }
                public function maySignIn(): bool { return $this->testRole !== 'inactive'; }
                public function can($abilities, $arguments = []): bool { return $abilities === 'edit_settings' && $this->testRole === 'admin'; }
            };
            $user->forceFill(['id' => 1]);
            $user->testRole = $role;
            Auth::guard('web')->setUser($user);
        }

        return $next($request);
    }
}

final class SftpFixtureStaff
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless($request->user()?->isStaff(), 403);

        return $next($request);
    }
}

function sftpFixture(string $fixturePath): Application
{
    foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/sessions', 'config'] as $path) {
        if (! is_dir($fixturePath.'/'.$path)) {
            mkdir($fixturePath.'/'.$path, 0700, true);
        }
    }
    if (! is_file($fixturePath.'/key')) {
        file_put_contents($fixturePath.'/key', 'base64:'.base64_encode(random_bytes(32)));
        touch($fixturePath.'/database.sqlite');
    }
    foreach (['CONFIG', 'SERVICES', 'PACKAGES', 'ROUTES', 'EVENTS'] as $kind) {
        $key = "APP_{$kind}_CACHE";
        $value = $fixturePath.'/bootstrap/cache/'.strtolower($kind).'.php';
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    $app = Application::configure(basePath: $fixturePath)
        ->withProviders([SftpTransferServiceProvider::class])
        ->withRouting(using: function (): void {
            Route::get('/login', fn () => response('Test login', 401))->name('login');
            Route::get('/dashboard', fn () => response('<a href="/system/settings/sftp">SFTP destinations</a>'));
        })
        ->withExceptions()
        ->withMiddleware(function (Middleware $middleware): void {
            $middleware->append(SftpFixtureIdentity::class);
            $middleware->alias(['staff' => SftpFixtureStaff::class]);
        })->create();
    $app->useEnvironmentPath($fixturePath);
    $app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($fixturePath): void {
        $app->make('config')->set([
            'app.env' => 'local', 'app.debug' => false,
            'app.key' => file_get_contents($fixturePath.'/key'), 'app.url' => 'http://localhost',
            'database.default' => 'sqlite',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => $fixturePath.'/database.sqlite',
            'cache.default' => 'array', 'logging.default' => 'null',
            'queue.default' => 'null', 'mail.default' => 'array',
            'session.driver' => 'file', 'session.files' => $fixturePath.'/storage/framework/sessions',
            'session.cookie' => 'sftp_fixture_session', 'session.secure' => false,
            'session.domain' => null, 'session.encrypt' => false,
            'view.compiled' => $fixturePath.'/storage/framework/views',
        ]);
    });

    return $app;
}
