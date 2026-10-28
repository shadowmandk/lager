<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('microbizz_id')->nullable()->unique();
            $table->boolean('microbizz_inactive')->default(false);
        });

        Schema::table('containers', function (Blueprint $table) {
            $table->unsignedBigInteger('microbizz_id')->nullable()->unique();
            // Slettet, deaktiveret eller ude af den valgte gruppe i Microbizz, mens den stod på lageret.
            $table->timestamp('microbizz_removed_at')->nullable();
        });

        Schema::table('activity_log', function (Blueprint $table) {
            // Sat, når ændringen kom fra et andet system end tavlen og opsætningen.
            $table->string('source')->nullable();
        });

        Schema::create('microbizz_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger');
            $table->string('status')->default('running');
            $table->unsignedInteger('customers_created')->default(0);
            $table->unsignedInteger('customers_updated')->default(0);
            $table->unsignedInteger('containers_created')->default(0);
            $table->unsignedInteger('containers_updated')->default(0);
            $table->unsignedInteger('containers_removed')->default(0);
            $table->json('notes')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microbizz_syncs');

        Schema::table('activity_log', fn (Blueprint $table) => $table->dropColumn('source'));

        Schema::table('containers', function (Blueprint $table) {
            $table->dropUnique(['microbizz_id']);
            $table->dropColumn(['microbizz_id', 'microbizz_removed_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['microbizz_id']);
            $table->dropColumn(['microbizz_id', 'microbizz_inactive']);
        });
    }
};
