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
        Schema::create('pull_request_candidates', function (Blueprint $table) {
            $table->id();

            /*
             * Candidates belong to the project, not to the connection.
             * Repointing a connection is refused once candidates exist, which
             * makes disconnect-and-reconnect the deliberate escape hatch —
             * and taking that hatch must not destroy the owner's triage.
             */
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            /*
             * Pull request numbers are unique per repository; the numeric id
             * is unique everywhere. Keying on the id keeps dedup correct even
             * for a project that has drawn from two repositories over time,
             * which the disconnect-and-reconnect hatch permits.
             */
            $table->unsignedBigInteger('github_id');
            $table->unsignedInteger('number');

            $table->string('title', 512);
            $table->text('body')->nullable();
            $table->string('author_login', 39)->nullable();
            $table->string('author_avatar_url')->nullable();
            $table->json('labels');
            $table->string('base_branch');
            $table->timestamp('merged_at');
            $table->string('html_url');

            $table->string('state');

            /*
             * When the owner first ruled on this entry. Null means "nobody
             * has looked at it", which is the only condition under which
             * ingestion is allowed to overwrite the wording.
             */
            $table->timestamp('curated_at')->nullable();

            $table->string('ingested_via');

            $table->timestamps();

            /* The dedup key. All three ingestion paths upsert on it. */
            $table->unique(['project_id', 'github_id']);

            /* The list query, in its only ordering. */
            $table->index(['project_id', 'state', 'merged_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pull_request_candidates');
    }
};
