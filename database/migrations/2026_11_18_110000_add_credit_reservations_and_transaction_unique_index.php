<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('reserved_balance', 18, 9)->default(0.000000000)->after('balance');
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->decimal('hold_amount', 18, 9)->nullable()->after('estimated_cost');
        });

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->unique(['type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropUnique(['type', 'reference_id']);
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('hold_amount');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reserved_balance');
        });
    }
};
