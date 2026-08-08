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
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('repository_connection_id')->constrained()->cascadeOnDelete();

            /*
             * GitHub retries a delivery it considers failed, reusing the same
             * identifier. The unique index below is what turns that retry
             * into a no-op instead of a second ingestion.
             */
            $table->string('delivery_id', 64);
            $table->string('event', 64);
            $table->string('action', 64)->nullable();
            $table->json('payload');

            $table->string('status');
            $table->string('reason')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->unique(['repository_connection_id', 'delivery_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
