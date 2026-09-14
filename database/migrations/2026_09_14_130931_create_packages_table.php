<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('repo')->unique();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('repo_url');
            $table->string('default_branch')->default('main');
            $table->string('sha')->nullable();
            $table->string('latest_version')->nullable();
            $table->timestamp('latest_release_at')->nullable();
            $table->json('composer')->nullable();
            $table->json('package_json')->nullable();
            $table->boolean('has_vue')->default(false);
            $table->boolean('has_react')->default(false);
            $table->unsignedInteger('stars')->default(0);
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('content_synced_at')->nullable();
            $table->timestamp('versions_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
