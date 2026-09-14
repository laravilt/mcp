<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->string('version');
            $table->string('normalized')->nullable();
            $table->string('tag')->nullable();
            $table->string('name')->nullable();
            $table->text('body')->nullable();
            $table->string('url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('on_packagist')->default(false);
            $table->boolean('on_github')->default(false);
            $table->timestamps();

            $table->unique(['package_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
