<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sftp_destinations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('host', 253);
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('username', 64);
            $table->string('directory', 1024);
            $table->string('host_fingerprint', 50);
            $table->longText('private_key');
            $table->text('private_key_passphrase')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sftp_destinations');
    }
};
