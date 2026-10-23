<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use phpseclib3\Crypt\EC;
use ProjectSendSftp\Destination;
use ProjectSendSftp\SaveDestination;
use Symfony\Component\HttpFoundation\Response;

function actor(bool $staff = true, bool $active = true, bool $canConfigure = true): User
{
    return new class($staff, $active, $canConfigure) extends User
    {
        public function __construct(private bool $staff, private bool $activeAccount, private bool $canConfigure) {}

        public function isStaff(): bool
        {
            return $this->staff;
        }

        public function maySignIn(): bool
        {
            return $this->activeAccount;
        }

        public function can($abilities, $arguments = []): bool
        {
            return $abilities === 'edit_settings' && $this->canConfigure;
        }
    };
}

$migrationPath = dirname(__DIR__).'/database/migrations';
check(in_array($migrationPath, array_map(realpath(...), $app->make('migrator')->paths()), true), 'Provider registers package migrations');
$migration = require $migrationPath.'/2026_10_21_000000_create_sftp_destinations_table.php';
$migration->up();

$key = EC::createKey('Ed25519');
$privateKey = $key->toString('OpenSSH');
$passphrase = ' test passphrase with spaces ';
$encryptedKey = $key->withPassword($passphrase)->toString('PKCS8');
$input = [
    'name' => 'Test database', 'host' => 'db.internal', 'username' => 'backup_inbox',
    'directory' => '/incoming/backups',
    'host_fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', 'fixture host key', true)), '='),
    'private_key' => $encryptedKey, 'private_key_passphrase' => $passphrase,
];
// Upgrade a pre-existing destination row as well as checking new model defaults.
DB::table('sftp_destinations')->insert(['name' => 'Before upgrade', 'host' => 'old.invalid', 'username' => 'old',
    'directory' => '/incoming', 'host_fingerprint' => $input['host_fingerprint'], 'private_key' => 'fixture ciphertext']);
$aclMigration = require $migrationPath.'/2026_10_23_000000_add_windows_acl_policy_to_sftp_destinations.php';
$aclMigration->up();
check(! (bool) DB::table('sftp_destinations')->value('windows_acl_managed'), 'Migration preserves strict Unix permissions for existing destinations');
DB::table('sftp_destinations')->delete();
$save = new SaveDestination;
$destination = $save->save(actor(), $input)->fresh();
check($destination->port === 22 && $destination->enabled === false && $destination->windows_acl_managed === false && $destination->host === 'db.internal', 'Destination round-trips with safe defaults');
check($destination->private_key === $encryptedKey && $destination->private_key_passphrase === $passphrase, 'Credentials decrypt with the application key');
$stored = DB::table('sftp_destinations')->first();
check($stored->private_key !== $encryptedKey && $stored->private_key_passphrase !== $passphrase, 'Database stores ciphertext for both credential fields');
$response = $destination->toArray();
check(! array_key_exists('private_key', $response) && ! array_key_exists('private_key_passphrase', $response)
    && $response['has_private_key'] && $response['has_private_key_passphrase'], 'Model responses expose credential presence without secrets');
check(! str_contains(serialize($destination), $passphrase) && ! str_contains(serialize($destination), $encryptedKey), 'PHP model serialisation does not expose decrypted credentials');

$identity = $destination->remoteIdentity();
$revision = $destination->revision();
$destination = $save->save(actor(), ['windows_acl_managed' => true], $destination)->fresh();
check($destination->windows_acl_managed === true && $destination->revision() !== $revision
    && $destination->remoteIdentity() === $identity, 'Windows ACL policy round-trips, changes the revision, and preserves the endpoint for recovery');
$destination = $save->save(actor(), ['name' => 'Policy retained'], $destination)->fresh();
check($destination->windows_acl_managed === true, 'Omitted permission policy preserves the saved administrator choice');
$destination = $save->save(actor(), ['windows_acl_managed' => false], $destination)->fresh();
check($destination->windows_acl_managed === false, 'Administrator can restore strict Unix permissions');

$staleDestination = clone $destination;
$destination = $save->save(actor(), ['name' => 'Renamed', 'private_key' => '', 'private_key_passphrase' => ''], $destination);
check($destination->private_key === $encryptedKey && $destination->private_key_passphrase === $passphrase, 'Blank credential inputs preserve the current pair');
$destination = $save->save(actor(), ['private_key' => $privateKey, 'enabled' => true], $destination);
check($destination->private_key === $privateKey && $destination->private_key_passphrase === null && $destination->enabled, 'Key rotation clears the previous passphrase and can enable a destination');
$destination = $save->save(actor(), ['name' => 'Stale edit', 'enabled' => false], $staleDestination);
check($destination->private_key === $privateKey && ! $destination->enabled, 'Stale metadata edits preserve rotated credentials and can disable a destination');

foreach ([actor(staff: false), actor(active: false), actor(canConfigure: false)] as $unauthorised) {
    foreach ([null, $destination] as $target) {
        try {
            $save->save($unauthorised, $input, $target);
            throw new RuntimeException('Unauthorised destination write succeeded.');
        } catch (AuthorizationException) {
            // Expected for both create and update.
        }
    }
}
check(Destination::count() === 1 && $destination->fresh()->name === 'Stale edit', 'Clients, inactive accounts, and staff without settings permission cannot create or update');

foreach ([
    ['host' => 'sftp://db.internal'], ['host' => ['invalid']], ['port' => 0], ['port' => 65536],
    ['directory' => '../backups'], ['directory' => '/incoming/../etc'], ['directory' => "/incoming\nbackup"],
    ['directory' => '/incoming\\backups'],
    ['directory' => ['invalid']], ['host_fingerprint' => 'SHA256:untrusted'], ['username' => "user\nname"],
    ['private_key' => 'not a private key'], ['private_key' => $key->getPublicKey()->toString('OpenSSH')],
    ['private_key_passphrase' => 'wrong password'], ['enabled' => 'yes'], ['windows_acl_managed' => 'yes'], ['windows_acl_managed' => null], ['windows_acl_managed' => ['1']],
] as $invalid) {
    $before = $destination->fresh()->getRawOriginal();
    try {
        $save->save(actor(), array_replace($input, $invalid), $destination);
        throw new RuntimeException('Invalid destination configuration was accepted: '.implode(', ', array_keys($invalid)));
    } catch (ValidationException $exception) {
        check($destination->fresh()->getRawOriginal() === $before, 'Invalid '.implode(', ', array_keys($invalid)).' leaves saved configuration unchanged');
        if (str_contains(json_encode($exception->errors(), JSON_THROW_ON_ERROR), $passphrase)) {
            throw new RuntimeException('Validation errors leaked a passphrase.');
        }
    }
}

$request = Request::create('/', 'POST', ['private_key_passphrase' => $passphrase]);
(new TrimStrings)->handle($request, fn (): Response => new Response);
check($request->input('private_key_passphrase') === $passphrase, 'Request middleware preserves spaces in passphrases');

$missingKey = $input;
unset($missingKey['private_key']);
try {
    $save->save(actor(), $missingKey);
    throw new RuntimeException('Destination without a private key was created.');
} catch (ValidationException) {
    check(Destination::count() === 1, 'Creation requires a private key and leaves no partial record');
}

$aclMigration->down();
check(! Schema::hasColumn('sftp_destinations', 'windows_acl_managed') && Destination::count() === 1, 'ACL migration rolls back without deleting destinations');
$aclMigration->up();
check(! Destination::first()->windows_acl_managed, 'ACL migration reapplies with a strict Unix default');
$aclMigration->down();

$migration->down();
check(! Schema::hasTable('sftp_destinations'), 'Migration rolls back its own table');
$migration->up();
check(Schema::hasTable('sftp_destinations'), 'Migration reapplies after rollback');
