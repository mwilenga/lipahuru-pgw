<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 32);
            $table->string('commission_type', 16);
            $table->decimal('value', 18, 4)->default(0);
            $table->timestamps();
            $table->unique(['merchant_id', 'operation'], 'merchant_commissions_merchant_operation_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_commissions');
    }
};
