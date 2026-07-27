<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('webhook_deliveries', 'sent_count')) {
            return;
        }

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->unsignedInteger('sent_count')->default(0)->after('attempt');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('webhook_deliveries', 'sent_count')) {
            return;
        }

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn('sent_count');
        });
    }
};
