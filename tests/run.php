<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\Facade;
use ProjectSendSftp\SftpTransferServiceProvider;

// An optional autoloader lets a release installation supply Laravel without
// installing a second copy. No ProjectSend application or .env is bootstrapped.
$packagePath = dirname(__DIR__);
$autoloadPath = $argv[1] ?? $packagePath.'/vendor/autoload.php';

if (! is_file($autoloadPath)) {
    fwrite(STDERR, "Install package dependencies, or supply a Laravel application's vendor/autoload.php.\n");
    exit(1);
}

$loader = require $autoloadPath;
$metadata = json_decode(file_get_contents($packagePath.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

foreach ($metadata['autoload']['psr-4'] as $namespace => $path) {
    $loader->addPsr4($namespace, $packagePath.'/'.$path);
}

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo "PASS: {$message}\n";
}

$files = new Filesystem;
$fixturePath = sys_get_temp_dir().'/projectsend-sftp-test-'.bin2hex(random_bytes(8));

try {
    $files->makeDirectory($fixturePath.'/bootstrap/cache', 0700, true);
    $files->makeDirectory($fixturePath.'/vendor/composer', 0700, true);

    // Exercise Laravel discovery with the installed-package metadata shape
    // Composer supplies. The actual host's manifests are never modified.
    $files->put($fixturePath.'/vendor/composer/installed.json', json_encode([
        'packages' => [$metadata],
    ], JSON_THROW_ON_ERROR));

    $app = new Application($fixturePath);
    $app->instance('config', new Repository([
        'app' => [
            'key' => 'base64:'.base64_encode(random_bytes(32)),
            'cipher' => 'AES-256-CBC',
            'providers' => [
                Illuminate\Events\EventServiceProvider::class,
                Illuminate\Routing\RoutingServiceProvider::class,
                Illuminate\Filesystem\FilesystemServiceProvider::class,
                Illuminate\Database\DatabaseServiceProvider::class,
                Illuminate\Database\MigrationServiceProvider::class,
                Illuminate\Encryption\EncryptionServiceProvider::class,
                Illuminate\Translation\TranslationServiceProvider::class,
                Illuminate\Validation\ValidationServiceProvider::class,
                Illuminate\Auth\AuthServiceProvider::class,
            ],
        ],
        'database' => [
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ],
    ]));
    Facade::setFacadeApplication($app);

    $manifest = $app->make(PackageManifest::class);
    $manifest->vendorPath = $fixturePath.'/vendor';
    check($manifest->providers() === [SftpTransferServiceProvider::class], 'Laravel discovers the package provider');

    $app->registerConfiguredProviders();
    require __DIR__.'/exception-handler.php';
    $app->boot();

    check($app->make(Illuminate\Contracts\Debug\ExceptionHandler::class) === $decoratedHandler,
        'Package boots with a pre-resolved exception-handler decorator without replacing it');

    $provider = $app->getProvider(SftpTransferServiceProvider::class);
    check($provider instanceof SftpTransferServiceProvider && $app->isBooted(), 'Standalone Laravel application boots with the package');

    $app->register(SftpTransferServiceProvider::class);
    check(count($app->getProviders(SftpTransferServiceProvider::class)) === 1, 'Repeated registration keeps one provider');

    require __DIR__.'/destinations.php';
} finally {
    $files->deleteDirectory($fixturePath);
}
