<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Local test preview only.');
}

// Local browser fixture. Serve explicitly on 127.0.0.1 only. It supplies a fake
// administrator and must never be exposed as a production application entry.
$packagePath = dirname(__DIR__);
$autoload = getenv('SFTP_TEST_AUTOLOAD') ?: dirname($packagePath, 2).'/vendor/autoload.php';
$loader = require $autoload;
$loader->addPsr4('ProjectSendSftp\\', $packagePath.'/src');
require __DIR__.'/fixture.php';

$fixturePath = getenv('SFTP_TEST_DIRECTORY') ?: sys_get_temp_dir().'/projectsend-sftp-preview';
$app = sftpFixture($fixturePath);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
if (! Illuminate\Support\Facades\Schema::hasTable('sftp_destinations')) {
    (require $packagePath.'/database/migrations/2026_10_21_000000_create_sftp_destinations_table.php')->up();
}
if (! Illuminate\Support\Facades\Schema::hasTable('sftp_transfers')) {
    (require $packagePath.'/database/migrations/2026_10_21_000001_create_sftp_transfers_table.php')->up();
}
if (! Illuminate\Support\Facades\Schema::hasColumn('sftp_transfers', 'attempt_id')) {
    (require $packagePath.'/database/migrations/2026_10_21_000002_add_sftp_recovery_state.php')->up();
}
if (! Illuminate\Support\Facades\Schema::hasColumn('sftp_destinations', 'windows_acl_managed')) {
    (require $packagePath.'/database/migrations/2026_10_23_000000_add_windows_acl_policy_to_sftp_destinations.php')->up();
}
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
