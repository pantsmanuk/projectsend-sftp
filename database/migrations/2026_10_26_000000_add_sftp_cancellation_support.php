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
            // Null on existing rows: completeness is checked against attempts.
            $table->json('previous_attempt_ids')->nullable();
        });
        Schema::create('sftp_cancelled_transfers', function (Blueprint $table): void {
            // A permanent replay guard, without user, file or connection data.
            $table->uuid('id')->primary();
        });
    }

    public function down(): void
    {
        // Stop workers and retire old queued jobs/forms before rolling back:
        // dropping UUID guards permits previously cancelled requests to recur.
        Schema::dropIfExists('sftp_cancelled_transfers');
        Schema::table('sftp_transfers', function (Blueprint $table): void {
            $table->dropColumn('previous_attempt_ids');
        });
    }
};
