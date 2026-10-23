<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sftp_destinations', function (Blueprint $table): void {
            $table->boolean('windows_acl_managed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sftp_destinations', function (Blueprint $table): void {
            $table->dropColumn('windows_acl_managed');
        });
    }
};
