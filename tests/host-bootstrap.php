<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\PackageManifest;

// Isolated actual-host bootstrap shared by the parent test and child workers.
function bootSftpHost(string $hostPath, string $packagePath, string $fixturePath, string $database = ':memory:'): Illuminate\Foundation\Application
{
    // These overrides affect this test process only. Neither a configured cache
    // path nor the application's .env may redirect the fixture to live services.
    foreach (['CONFIG', 'SERVICES', 'PACKAGES', 'ROUTES', 'EVENTS'] as $kind) {
        $key = "APP_{$kind}_CACHE";
        $value = $fixturePath.'/bootstrap/cache/'.strtolower($kind).'.php';
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    $loader = require $hostPath.'/vendor/autoload.php';
    $metadata = json_decode(file_get_contents($packagePath.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ($metadata['autoload']['psr-4'] as $namespace => $path) {
        $loader->addPsr4($namespace, $packagePath.'/'.$path);
    }

    $files = new Filesystem;

    if (! is_dir($fixturePath.'/bootstrap/cache')) { $files->makeDirectory($fixturePath.'/bootstrap/cache', 0700, true); }
    if (! is_dir($fixturePath.'/vendor/composer')) { $files->makeDirectory($fixturePath.'/vendor/composer', 0700, true); }
    if (! is_dir($fixturePath.'/storage/framework/views')) { $files->makeDirectory($fixturePath.'/storage/framework/views', 0700, true); }

    $installed = json_decode(file_get_contents($hostPath.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
    $packages = $installed['packages'] ?? $installed;
    $packages[] = $metadata;
    if (! is_file($fixturePath.'/vendor/composer/installed.json')) { $files->put($fixturePath.'/vendor/composer/installed.json', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR)); }

    $app = require $hostPath.'/bootstrap/app.php';
    $app->useEnvironmentPath($fixturePath);
    $app->useStoragePath($fixturePath.'/storage');
    $manifest = new PackageManifest(
        $files, $fixturePath, $fixturePath.'/bootstrap/cache/packages.php',
    );
    $manifest->vendorPath = $fixturePath.'/vendor';
    $app->instance(PackageManifest::class, $manifest);

    if (! is_file($fixturePath.'/app-key')) { file_put_contents($fixturePath.'/app-key', 'base64:'.base64_encode(random_bytes(32))); }
    if ($database !== ':memory:' && ! is_file($database)) { touch($database); }
    $app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($fixturePath, $database): void {
        $app->make('config')->set([
            'app.env' => 'testing',
            'app.key' => file_get_contents($fixturePath.'/app-key'),
            'cache.default' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => $database,
            'logging.default' => 'null',
            'mail.default' => 'array',
            'queue.default' => 'null',
            'session.driver' => 'array',
            'filesystems.disks.sftp_test_local' => ['driver' => 'local', 'root' => $fixturePath.'/source-files', 'throw' => false],
            'view.compiled' => $fixturePath.'/storage/framework/views',
        ]);
    });

    $app->make(Kernel::class)->bootstrap();

    return $app;
}
