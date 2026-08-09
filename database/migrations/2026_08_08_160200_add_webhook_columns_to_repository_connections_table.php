<?php

use App\Enums\WebhookStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('repository_connections', function (Blueprint $table) {
            /*
             * Both are generated for every connection, whether or not we
             * manage to create the hook ourselves: the manual path needs the
             * URL and the secret in order to exist at all.
             */
            $table->string('webhook_token', 64)->nullable();
            $table->text('webhook_secret')->nullable();

            /* Set only when we created the hook through the API. */
            $table->unsignedBigInteger('webhook_id')->nullable();

            $table->string('webhook_status')->default(WebhookStatus::ManualSetupRequired->value);
            $table->timestamp('webhook_last_delivery_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
        });

        /*
         * Connections made under the previous spec have a token carrying no
         * Webhooks permission. Nothing about them breaks: they get an address
         * and a secret here, sit at `manual_setup_required`, and keep working
         * for backfill and sync, which need only pull request read access.
         */
        /*
         * A row at a time, because each one needs its own token and its own
         * secret — there is no set-based form of "give everybody a different
         * random value". Wrapped in a transaction so a deploy pays for one
         * commit rather than one per connection.
         */
        DB::transaction(function () {
            DB::table('repository_connections')
                ->select('id')
                ->orderBy('id')
                ->chunk(500, function ($connections) {
                    foreach ($connections as $connection) {
                        DB::table('repository_connections')
                            ->where('id', $connection->id)
                            ->update([
                                'webhook_token' => Str::random(64),
                                'webhook_secret' => Crypt::encryptString(Str::random(64)),
                            ]);
                    }
                });
        });

        Schema::table('repository_connections', function (Blueprint $table) {
            $table->string('webhook_token', 64)->nullable(false)->change();
            $table->text('webhook_secret')->nullable(false)->change();

            $table->unique('webhook_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repository_connections', function (Blueprint $table) {
            $table->dropUnique(['webhook_token']);

            $table->dropColumn([
                'webhook_token',
                'webhook_secret',
                'webhook_id',
                'webhook_status',
                'webhook_last_delivery_at',
                'last_synced_at',
            ]);
        });
    }
};
