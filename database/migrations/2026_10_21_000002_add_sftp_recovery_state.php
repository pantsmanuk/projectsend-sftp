<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sftp_transfers', function (Blueprint $table): void {
            $table->uuid('attempt_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('execution_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->json('remote_identity')->nullable();
            $table->json('actor_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sftp_transfers', function (Blueprint $table): void {
            $table->dropIndex(['lease_expires_at']);
            $table->dropColumn(['attempt_id', 'attempts', 'execution_token', 'lease_expires_at', 'remote_identity', 'actor_snapshot']);
        });
    }
};
