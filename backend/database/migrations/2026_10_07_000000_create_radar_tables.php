<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->string('id', 36)->primary();
                $table->string('name', 120);
                $table->string('email', 190)->unique();
                $table->string('password_hash');
                $table->dateTime('created_at');
            });
        }

        if (! Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table): void {
                $table->string('id', 36)->primary();
                $table->string('user_id', 36);
                $table->text('url');
                $table->text('name');
                $table->text('price');
                $table->text('image');
                $table->string('source')->default('');
                $table->unsignedSmallInteger('interval_minutes');
                $table->dateTime('created_at');
                $table->dateTime('last_checked_at')->nullable();
                $table->dateTime('next_check_at');
                $table->text('error')->nullable();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index('next_check_at', 'idx_products_due');
                $table->index('user_id', 'idx_products_user');
            });
        }
    }

    public function down(): void
    {
        // Conserva las tablas y los datos al revertir migraciones.
    }
};
