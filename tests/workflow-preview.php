<?php

declare(strict_types=1);

// Synthetic actual-host UI only. Never expose this router beyond loopback.
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Local test preview only.');
}

require __DIR__.'/host-bootstrap.php';
$packagePath = dirname(__DIR__);
$hostPath = dirname($packagePath, 2);
$fixturePath = getenv('SFTP_WORKFLOW_DIRECTORY') ?: sys_get_temp_dir().'/projectsend-sftp-workflow-preview';
$app = bootSftpHost($hostPath, $packagePath, $fixturePath, $fixturePath.'/database.sqlite');
$app->instance('env', 'local');
$app->make('config')->set(['queue.default' => 'database', 'session.driver' => 'file', 'session.files' => $fixturePath.'/sessions']);
if (! is_dir($fixturePath.'/sessions')) { mkdir($fixturePath.'/sessions', 0700); }

if (! Illuminate\Support\Facades\Schema::hasTable('files')) {
    $migrator = $app->make('migrator');
    $migrator->getRepository()->createRepository();
    $migrator->run([$hostPath.'/database/migrations', $packagePath.'/database/migrations']);
    foreach (['staff', 'admin', 'empty'] as $role) {
        $model = App\Modules\Identity\Models\Role::create(['name' => 'Preview '.$role, 'is_administrator' => $role === 'admin']);
        if ($role === 'staff') { Illuminate\Support\Facades\DB::table('role_permission')->insert(['role_id' => $model->id, 'permission' => 'upload']); }
        Illuminate\Support\Facades\DB::table('users')->insert(['name' => 'Preview '.$role, 'email' => $role.'@example.invalid', 'password' => 'unused', 'active' => true, 'type' => 'staff', 'role_id' => $model->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $payload = "Synthetic preview backup only\n";
    Illuminate\Support\Facades\Storage::disk('sftp_test_local')->put('preview.dump', $payload);
    for ($i = 1; $i <= 28; $i++) {
        Illuminate\Support\Facades\DB::table('files')->insert([
            'uploaded_by' => 2, 'name' => $i === 1 ? 'Example customer database' : 'Preview backup '.$i,
            'original_name' => 'preview-'.$i.'.dump', 'path' => 'preview.dump', 'disk' => 'sftp_test_local',
            'mime_type' => 'application/octet-stream', 'slug' => 'preview-'.$i,
            'size' => strlen($payload), 'checksum' => hash('sha256', $payload), 'scan_status' => 'clean',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    ProjectSendSftp\Destination::create([
        'name' => 'Preview database instance', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'preview',
        'directory' => '/synthetic-preview', 'host_fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', 'preview', true)), '='),
        'private_key' => phpseclib3\Crypt\EC::createKey('Ed25519')->toString('OpenSSH'), 'enabled' => true,
    ]);
}

if (! Illuminate\Support\Facades\Schema::hasColumn('sftp_destinations', 'windows_acl_managed')) {
    (require $packagePath.'/database/migrations/2026_10_23_000000_add_windows_acl_policy_to_sftp_destinations.php')->up();
}

// Test-only identity switch. No login or external account is involved.
$role = $_GET['preview_role'] ?? $_COOKIE['sftp_preview_role'] ?? 'staff';
if (! in_array($role, ['staff', 'admin', 'empty'], true)) { $role = 'staff'; }
if (isset($_GET['preview_role'])) { setcookie('sftp_preview_role', $role, ['path' => '/', 'httponly' => true, 'samesite' => 'Strict']); }
Illuminate\Support\Facades\Auth::guard('web')->setUser(App\Models\User::where('email', $role.'@example.invalid')->firstOrFail());

// Browser-only progress simulation. No worker is run and no SSH is contacted.
Illuminate\Support\Facades\Route::middleware('web')->post('/__fixture/state/{id}', function (Illuminate\Http\Request $request, string $id) {
    $input = $request->validate(['status' => ['required', Illuminate\Validation\Rule::enum(ProjectSendSftp\TransferStatus::class)]]);
    $row = ProjectSendSftp\Transfer::findOrFail($id);
    $row->update(['status' => $input['status'], 'bytes_sent' => $row->total_bytes,
        'started_at' => $input['status'] === 'queued' ? null : ($row->started_at ?? now()),
        'finished_at' => in_array($input['status'], ['queued', 'running']) ? null : now(),
    ]);
    return response()->json(['ok' => true]);
});
$request = Illuminate\Http\Request::capture();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
