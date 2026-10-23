<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sftp_transfers', function (Blueprint $table): void {
            // The UUID identifies this single attempt, including uncertain results.
            $table->uuid('id')->primary();
            // Host IDs are retained for history even after account/file deletion.
            $table->unsignedBigInteger('requested_by')->index();
            $table->unsignedBigInteger('file_id')->index();
            $table->foreignId('destination_id')->nullable()->index()->constrained('sftp_destinations')->nullOnDelete();
            $table->string('destination_name');
            $table->char('destination_revision', 64);
            $table->string('file_name');
            $table->string('filename');
            $table->string('source_disk');
            $table->text('source_path');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('total_bytes');
            $table->unsignedBigInteger('bytes_sent')->default(0);
            $table->string('status', 20)->default('queued')->index();
            $table->text('remote_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sftp_transfers');
    }
};
