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
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pf_generation_id')->nullable()->unique();
            $table->string('idempotency_key')->nullable()->index();
            $table->text('prompt');
            $table->string('size', 20)->default('1024x1024');
            $table->string('quality', 20)->default('medium');
            $table->json('reference_images')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 20)->default('queued')->index();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->decimal('estimated_cost', 18, 9)->nullable();
            $table->decimal('pf_cost', 18, 9)->nullable();
            $table->decimal('user_cost', 18, 9)->nullable();
            $table->json('usage')->nullable();
            $table->json('rates')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
