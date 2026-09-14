<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('title');
            $table->json('headings')->nullable();
            $table->longText('content');
            $table->string('source_url');
            $table->string('sha')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['package_id', 'path']);
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            try {
                DB::statement(
                    "CREATE VIRTUAL TABLE documents_fts USING fts5(title, headings, content, tokenize = 'porter unicode61')"
                );
            } catch (Throwable) {
                // FTS5 is not compiled into this SQLite build; search falls back to LIKE.
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TABLE IF EXISTS documents_fts');
        }

        Schema::dropIfExists('documents');
    }
};
