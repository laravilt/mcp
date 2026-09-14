<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every public repository seen in the GitHub organization, including the
     * ones that turned out not to be Laravilt packages.
     */
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('default_branch')->default('main');
            $table->string('sha')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->boolean('is_package')->default(false);
            $table->string('composer_name')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
