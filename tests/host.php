<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\UserType;
use ProjectSendSftp\SftpTransferServiceProvider;

$hostPath = realpath($argv[1] ?? dirname(__DIR__, 3));

if ($hostPath === false || ! is_file($hostPath.'/bootstrap/app.php') || ! is_file($hostPath.'/vendor/autoload.php')) {
    fwrite(STDERR, "Supply the path to a ProjectSend development checkout with installed dependencies.\n");
    exit(1);
}

$packagePath = dirname(__DIR__);
$fixturePath = sys_get_temp_dir().'/projectsend-sftp-host-'.bin2hex(random_bytes(8));

require __DIR__.'/host-bootstrap.php';
$files = null;
try {
    $app = bootSftpHost($hostPath, $packagePath, $fixturePath, in_array('--sftp', $argv, true) ? $fixturePath.'/database.sqlite' : ':memory:');
    $files = new Filesystem;

    if (! $app->isBooted() || ! $app->getProvider(SftpTransferServiceProvider::class) instanceof SftpTransferServiceProvider) {
        throw new RuntimeException('ProjectSend did not boot with the discovered SFTP provider.');
    }

    echo "PASS: ProjectSend boots with the discovered package provider and isolated test configuration\n";

    $admin = new User(['type' => UserType::Staff, 'active' => true]);
    $admin->setRelation('role', new Role(['is_administrator' => true]));
    if (! Gate::forUser($admin)->allows('sftp.manage-destinations')) {
        throw new RuntimeException('Active ProjectSend staff administrator was refused.');
    }
    foreach ([
        ['type' => UserType::Client], ['active' => false], ['expires_at' => now()->subDay()],
    ] as $attributes) {
        $refused = clone $admin;
        $refused->forceFill($attributes);
        if (Gate::forUser($refused)->allows('sftp.manage-destinations')) {
            throw new RuntimeException('Client, inactive, or expired ProjectSend account was allowed.');
        }
    }
    $staff = clone $admin;
    $staff->setRelation('role', null);
    if (Gate::forUser($staff)->allows('sftp.manage-destinations') || Gate::forUser(null)->allows('sftp.manage-destinations')) {
        throw new RuntimeException('Guest or ProjectSend staff without settings permission was allowed.');
    }
    echo "PASS: Administration gate respects real ProjectSend staff, permission, and account-state rules\n";

    Illuminate\Support\Facades\Auth::guard('web')->setUser($admin);
    $navigation = new App\Modules\Platform\Navigation\Events\ResolvingNavigationLinks(isStaff: true);
    Illuminate\Support\Facades\Event::dispatch($navigation);
    if (! in_array('/system/settings/sftp', array_column($navigation->links, 'url'), true)) {
        throw new RuntimeException('Settings administrator did not receive the SFTP navigation link.');
    }
    Illuminate\Support\Facades\Auth::guard('web')->setUser($staff);
    $navigation = new App\Modules\Platform\Navigation\Events\ResolvingNavigationLinks(isStaff: true);
    Illuminate\Support\Facades\Event::dispatch($navigation);
    if (in_array('/system/settings/sftp', array_column($navigation->links, 'url'), true)) {
        throw new RuntimeException('Staff without settings permission received the SFTP navigation link.');
    }
    echo "PASS: Actual ProjectSend navigation hook adds the link only for authorised staff\n";

    $session = $app->make('session')->driver();
    $session->start();
    $request = Request::create('http://localhost/system/settings/sftp', 'POST', [
        'name' => 'Keep this field', 'private_key' => 'test-only secret key',
        'private_key_passphrase' => 'test-only secret passphrase',
    ]);
    $request->setLaravelSession($session);
    $app->instance('request', $request);
    $app->make('redirect')->setSession($session);
    $response = $app->make(ExceptionHandler::class)->render(
        $request, ValidationException::withMessages(['host' => 'Invalid host.']),
    );
    $old = $session->getOldInput();
    if (! $response->isRedirection() || ($old['name'] ?? null) !== 'Keep this field'
        || array_key_exists('private_key', $old) || array_key_exists('private_key_passphrase', $old)) {
        throw new RuntimeException('Validation redirect did not preserve ordinary input while excluding credentials.');
    }
    echo "PASS: ProjectSend validation redirects never flash SFTP credentials into the session\n";

    require __DIR__.'/sources.php';
    require __DIR__.'/workflow.php';
    require __DIR__.'/transfers.php';
} catch (Throwable $exception) {
    fwrite(STDERR, $exception."\n");
    $testExitCode = 1;
} finally {
    if ($files !== null) { $files->deleteDirectory($fixturePath); }
}

exit($testExitCode ?? 0);
