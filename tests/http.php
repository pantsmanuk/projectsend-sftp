<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use phpseclib3\Crypt\EC;
use ProjectSendSftp\Destination;

$packagePath = dirname(__DIR__);
$autoload = $argv[1] ?? $packagePath.'/vendor/autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, "Supply vendor/autoload.php from an installed Laravel 12 application.\n");
    exit(1);
}
$loader = require $autoload;
$loader->addPsr4('ProjectSendSftp\\', $packagePath.'/src');
require __DIR__.'/fixture.php';
Request::enableHttpMethodParameterOverride();

function verify(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
    echo "PASS: {$message}\n";
}

$fixturePath = sys_get_temp_dir().'/projectsend-sftp-http-'.bin2hex(random_bytes(8));
try {
    $app = sftpFixture($fixturePath);
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $migration = require $packagePath.'/database/migrations/2026_10_21_000000_create_sftp_destinations_table.php';
    $migration->up();
    (require $packagePath.'/database/migrations/2026_10_21_000001_create_sftp_transfers_table.php')->up();
    (require $packagePath.'/database/migrations/2026_10_21_000002_add_sftp_recovery_state.php')->up();
    (require $packagePath.'/database/migrations/2026_10_23_000000_add_windows_acl_policy_to_sftp_destinations.php')->up();
    $cookies = [];
    $send = function (string $method, string $path, array $input = [], array $files = [], array $headers = []) use ($app, $kernel, &$cookies) {
        Auth::forgetGuards();
        $request = Request::create('http://localhost'.$path, $method, $input, $cookies, $files, array_replace(['HTTP_ACCEPT' => 'text/html'], $headers));
        $response = $kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) { $cookies[$cookie->getName()] = $cookie->getValue(); }
        $kernel->terminate($request, $response);
        return $response;
    };
    $index = $send('GET', '/system/settings/sftp');
    verify($index->getStatusCode() === 200 && str_contains($index->getContent(), 'No destinations yet'), 'Empty destination page renders through web middleware');
    $form = $send('GET', '/system/settings/sftp/create');
    verify($form->getStatusCode() === 200 && str_contains($form->getContent(), 'multipart/form-data'), 'Create form renders without frontend build assets');
    preg_match('/name="_token" value="([^"]+)"/', $form->getContent(), $match);
    $token = $match[1] ?? throw new RuntimeException('Missing CSRF token.');
    $keyPath = $fixturePath.'/test-key';
    file_put_contents($keyPath, EC::createKey('Ed25519')->toString('OpenSSH'));
    $input = ['_token' => $token, 'name' => 'Fixture database', 'host' => 'db.internal', 'port' => 22, 'username' => 'backup', 'directory' => '/incoming', 'host_fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', 'fixture', true)), '='), 'enabled' => '0', 'windows_acl_managed' => '1'];
    verify($send('POST', '/system/settings/sftp', $input + ['_ignored' => 'anything'], ['private_key' => new UploadedFile($keyPath, 'test-key', null, null, true)], ['HTTP_X_SFTP_TEST_ROLE' => 'staff'])->getStatusCode() === 403 && Destination::count() === 0, 'Staff without settings permission cannot create a destination');
    $badCsrf = $input;
    $badCsrf['_token'] = 'invalid';
    verify($send('POST', '/system/settings/sftp', $badCsrf)->getStatusCode() === 419, 'Invalid CSRF token is rejected');
    $created = $send('POST', '/system/settings/sftp', $input, ['private_key' => new UploadedFile($keyPath, 'test-key', null, null, true)]);
    verify($created->getStatusCode() === 303 && Destination::count() === 1, 'Authorised key upload creates a destination and redirects');
    verify(str_contains($send('GET', '/system/settings/sftp')->getContent(), 'backup@db.internal:22'), 'List renders the complete username, hostname, and port');
    $destination = Destination::first();
    verify($destination->windows_acl_managed === true, 'Administrator key-upload form explicitly opts into server-managed Windows ACLs');
    $edit = $send('GET', '/system/settings/sftp/'.$destination->id.'/edit');
    verify($edit->getStatusCode() === 200 && str_contains($edit->getContent(), 'A key is saved.') && ! str_contains($edit->getContent(), 'BEGIN OPENSSH PRIVATE KEY'), 'Edit form shows saved-key presence without its contents');
    preg_match('/name="_token" value="([^"]+)"/', $edit->getContent(), $match);
    $update = ['_token' => $match[1], '_method' => 'PATCH', 'name' => 'Changed database', 'enabled' => '1', 'windows_acl_managed' => '0'];
    verify($send('POST', '/system/settings/sftp/'.$destination->id, $update)->getStatusCode() === 303 && $destination->fresh()->enabled && $destination->fresh()->windows_acl_managed === false, 'Edit form updates and enables a destination without replacing its key');
    $update['enabled'] = '0';
    verify($send('POST', '/system/settings/sftp/'.$destination->id, $update)->getStatusCode() === 303 && ! $destination->fresh()->enabled, 'Edit form disables a destination');
    $update['_revision'] = $destination->fresh()->revision();
    Destination::query()->whereKey($destination->id)->update(['host' => 'newer.internal']);
    $stale = $send('POST', '/system/settings/sftp/'.$destination->id, $update);
    verify($stale->isRedirection() && $app->make('session')->driver()->get('errors')->has('_revision') && $destination->fresh()->host === 'newer.internal', 'Stale form cannot overwrite a newer destination');
    $invalid = $update;
    unset($invalid['_revision']);
    $invalid['host'] = 'sftp://invalid';
    $invalid['windows_acl_managed'] = '1';
    $invalid['private_key_passphrase'] = 'fixture-only-secret';
    $failed = $send('POST', '/system/settings/sftp/'.$destination->id, $invalid);
    $recovery = $send('GET', '/system/settings/sftp/'.$destination->id.'/edit');
    verify($failed->isRedirection() && str_contains($recovery->getContent(), 'Destination not saved')
        && str_contains($recovery->getContent(), 'sftp://invalid')
        && preg_match('/id="windows_acl_managed"[^>]*checked/', $recovery->getContent()) === 1
        && $destination->fresh()->windows_acl_managed === false && ! str_contains($recovery->getContent(), 'fixture-only-secret'), 'Validation recovery keeps ordinary input and discards secrets');
    foreach (['client', 'staff', 'inactive', 'guest'] as $role) {
        $status = $send('GET', '/system/settings/sftp', [], [], ['HTTP_X_SFTP_TEST_ROLE' => $role])->getStatusCode();
        verify(in_array($status, [302, 403], true), 'Configuration page refuses '.$role);
        $mutation = $send('POST', '/system/settings/sftp/'.$destination->id, array_replace($update, ['windows_acl_managed' => '1']), [], ['HTTP_X_SFTP_TEST_ROLE' => $role]);
        verify(in_array($mutation->getStatusCode(), [302, 403], true) && $destination->fresh()->name === 'Changed database' && $destination->fresh()->windows_acl_managed === false, 'Configuration mutation refuses '.$role);
    }
    $inertia = $send('GET', '/system/settings/sftp', [], [], ['HTTP_X_INERTIA' => 'true']);
    verify($inertia->getStatusCode() === 409 && $inertia->headers->get('X-Inertia-Location') === 'http://localhost/system/settings/sftp', 'Inertia navigation hands off to a same-tab full-page visit');
    verify(str_contains($edit->headers->get('Cache-Control'), 'no-store'), 'Configuration pages prevent HTTP caching');

    $deletePath = '/system/settings/sftp/'.$destination->id.'/delete';
    $confirmation = $send('GET', $deletePath);
    verify($confirmation->getStatusCode() === 200 && str_contains($confirmation->getContent(), 'Changed database')
        && str_contains($confirmation->getContent(), 'backup@newer.internal:22')
        && str_contains($confirmation->getContent(), 'Keep destination') && Destination::count() === 1, 'Deletion confirmation names the destination and connection and does not delete on GET');
    preg_match('/name="_token" value="([^"]+)"/', $confirmation->getContent(), $match);
    $deletion = ['_token' => $match[1], '_method' => 'DELETE', '_revision' => $destination->fresh()->revision(), 'confirm_delete' => '1'];
    foreach (['client', 'staff', 'inactive', 'guest'] as $role) {
        $headers = ['HTTP_X_SFTP_TEST_ROLE' => $role];
        verify(in_array($send('GET', $deletePath, [], [], $headers)->getStatusCode(), [302, 403], true)
            && in_array($send('POST', '/system/settings/sftp/'.$destination->id, $deletion, [], $headers)->getStatusCode(), [302, 403], true)
            && Destination::whereKey($destination->id)->exists(), 'Deletion refuses '.$role);
    }
    $missingConfirmation = $deletion;
    unset($missingConfirmation['confirm_delete']);
    verify($send('POST', '/system/settings/sftp/'.$destination->id, $missingConfirmation)->isRedirection()
        && Destination::whereKey($destination->id)->exists(), 'Deletion requires explicit confirmation');
    $badToken = $deletion;
    $badToken['_token'] = 'invalid';
    verify($send('POST', '/system/settings/sftp/'.$destination->id, $badToken)->getStatusCode() === 419
        && Destination::whereKey($destination->id)->exists(), 'Deletion rejects invalid CSRF tokens');
    Destination::whereKey($destination->id)->update(['host' => 'changed-again.internal']);
    verify($send('POST', '/system/settings/sftp/'.$destination->id, $deletion)->isRedirection()
        && $app->make('session')->driver()->get('errors')->has('_revision')
        && Destination::whereKey($destination->id)->exists(), 'Stale confirmation cannot delete changed configuration');
    $other = $destination->fresh()->replicate();
    $other->name = 'Another destination';
    $other->save();
    $deletion['_revision'] = $destination->fresh()->revision();
    $history = ProjectSendSftp\Transfer::create([
        'id' => (string) Illuminate\Support\Str::uuid(), 'requested_by' => 1, 'file_id' => 1,
        'destination_id' => $destination->id, 'destination_name' => $destination->name,
        'destination_revision' => $destination->fresh()->revision(), 'file_name' => 'Synthetic backup',
        'filename' => 'synthetic.dump', 'source_disk' => 'fixture', 'source_path' => 'synthetic.dump',
        'sha256' => hash('sha256', ''), 'total_bytes' => 0, 'status' => ProjectSendSftp\TransferStatus::Queued,
    ]);
    foreach ([ProjectSendSftp\TransferStatus::Queued, ProjectSendSftp\TransferStatus::Running, ProjectSendSftp\TransferStatus::Uncertain] as $status) {
        $history->update(['status' => $status]);
        $send('POST', '/system/settings/sftp/'.$destination->id, $deletion);
        verify(Destination::whereKey($destination->id)->exists() && $app->make('session')->driver()->get('errors')->has('_revision'),
            'Deletion refuses destinations with '.$status->value.' transfers');
    }
    $history->update(['status' => ProjectSendSftp\TransferStatus::Completed]);
    $deleted = $send('POST', '/system/settings/sftp/'.$destination->id, $deletion);
    verify($deleted->getStatusCode() === 303 && ! Destination::whereKey($destination->id)->exists()
        && Destination::whereKey($other->id)->exists() && Destination::count() === 1,
        'Confirmed deletion removes the destination and credential row while preserving other destinations');
    verify($history->fresh()->destination_id === null && $history->fresh()->destination_name === $destination->name,
        'Deleting configuration retains completed transfer history without an orphan destination reference');
} catch (Throwable $exception) {
    fwrite(STDERR, $exception."\n");
    throw $exception;
} finally {
    (new Filesystem)->deleteDirectory($fixturePath);
}
