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
        Schema::create('movies', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('director')->nullable();
            $table->integer('year');
            $table->string('genre')->nullable();
            $table->decimal('rating', 3, 1)->default(0);
            $table->string('poster_url', 1024)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['title', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movies');
    }
};
