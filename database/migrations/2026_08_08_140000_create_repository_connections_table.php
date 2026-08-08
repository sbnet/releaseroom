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
        Schema::create('repository_connections', function (Blueprint $table) {
            $table->id();

            /*
             * A project holds at most one repository. The owner is carried
             * here as well: the "one repository per user" rule spans two
             * tables, and denormalizing it turns a checked-then-inserted race
             * into a database guarantee. Safe while a project's owner is
             * immutable — the column to revisit when team access lands.
             */
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * The numeric GitHub id is immutable: it survives renames and
             * transfers, which the owner and name do not.
             */
            $table->unsignedBigInteger('github_id');
            $table->string('owner', 39);
            $table->string('name', 100);
            $table->boolean('is_private');
            $table->string('default_branch');

            $table->text('token');
            $table->string('token_last_four', 4);
            $table->timestamp('token_expires_at')->nullable();

            $table->string('status');
            $table->string('last_error_code')->nullable();
            $table->timestamp('last_checked_at');

            $table->timestamps();

            $table->unique(['user_id', 'github_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repository_connections');
    }
};
