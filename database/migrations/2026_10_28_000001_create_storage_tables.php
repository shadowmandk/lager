<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_buffer')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('storage_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('depth');
            $table->unsignedTinyInteger('height');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('containers', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('name')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            $table->foreignId('storage_row_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('depth')->nullable();
            $table->unsignedTinyInteger('level')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['storage_row_id', 'depth', 'level']);
            $table->index('status');
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->foreignId('container_id')->constrained();
            $table->string('from_status')->nullable();
            $table->foreignId('from_row_id')->nullable()->constrained('storage_rows')->nullOnDelete();
            $table->unsignedTinyInteger('from_depth')->nullable();
            $table->unsignedTinyInteger('from_level')->nullable();
            $table->string('from_label')->nullable();
            $table->string('to_status')->nullable();
            $table->foreignId('to_row_id')->nullable()->constrained('storage_rows')->nullOnDelete();
            $table->unsignedTinyInteger('to_depth')->nullable();
            $table->unsignedTinyInteger('to_level')->nullable();
            $table->string('to_label')->nullable();
            $table->foreignId('from_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('to_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('from_name')->nullable();
            $table->string('to_name')->nullable();
            $table->foreignId('undoes_id')->nullable()->constrained('activity_log')->nullOnDelete();
            $table->foreignId('undone_by_id')->nullable()->constrained('activity_log')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('containers');
        Schema::dropIfExists('operators');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('storage_rows');
        Schema::dropIfExists('zones');
        Schema::dropIfExists('warehouses');
    }
};
