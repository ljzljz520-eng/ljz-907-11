<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->string('translated_title')->nullable()->after('title');
            $table->string('country')->nullable()->after('actors');
            $table->string('language')->nullable()->after('country');
            $table->string('imdb_rating')->nullable()->after('rating');
            $table->string('imdb_link')->nullable()->after('imdb_rating');
            $table->string('douban_link')->nullable()->after('imdb_link');
            $table->string('runtime')->nullable()->after('language');
            $table->string('writer')->nullable()->after('director');
            $table->text('awards')->nullable()->after('description');
            $table->text('screenshots')->nullable()->after('awards'); // Store as JSON array of URLs
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->dropColumn([
                'translated_title', 'country', 'language', 'imdb_rating', 
                'imdb_link', 'douban_link', 'runtime', 'writer', 
                'awards', 'screenshots'
            ]);
        });
    }
};