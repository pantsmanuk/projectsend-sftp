<?php

declare(strict_types=1);

namespace ProjectSendSftp;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;

final class SftpTransferServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sftp');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        if ($this->app->runningInConsole()) {
            $this->commands([RecoverTransfers::class, CancelTransfer::class]);
        }

        Event::listen('App\\Modules\\Platform\\Navigation\\Events\\ResolvingNavigationLinks', function ($event): void {
            if ($event->isStaff && $this->app->make(Gate::class)->allows('sftp.transfer')) {
                $event->add(__('SFTP transfers'), route('sftp.transfers.index', [], false));
            }
            if ($event->isStaff && $this->app->make(Gate::class)->allows('sftp.manage-destinations')) {
                $event->add(__('SFTP destinations'), route('sftp.destinations.index', [], false));
            }
        });

        $this->callAfterResolving(Gate::class, function (Gate $gate): void {
            $gate->define('sftp.transfer', fn (Authenticatable&Authorizable $user): bool =>
                method_exists($user, 'isStaff') && $user->isStaff()
                && method_exists($user, 'maySignIn') && $user->maySignIn()
            );
            $gate->define('sftp.manage-destinations', fn (Authenticatable&Authorizable $user): bool =>
                method_exists($user, 'isStaff') && $user->isStaff()
                && method_exists($user, 'maySignIn') && $user->maySignIn()
                && $user->can('edit_settings')
            );
        });

        // Collision decorates the contract in CLI processes. Configure only
        // Laravel's handler, which owns validation input flashing in web requests.
        $this->callAfterResolving(Handler::class, function (Handler $handler): void {
            $handler->dontFlash(['private_key', 'private_key_passphrase']);
        });

        // A passphrase can deliberately start or end with spaces.
        TrimStrings::except(['private_key', 'private_key_passphrase']);
    }
}
