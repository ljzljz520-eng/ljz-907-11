<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            // 增加字段长度以容纳更长的数据
            $table->text('release_date')->nullable()->change();
            $table->string('director', 512)->nullable()->change();
            $table->string('writer', 512)->nullable()->change();
            $table->string('country', 512)->nullable()->change();
            $table->string('language', 512)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->string('release_date', 255)->nullable()->change();
            $table->string('director', 255)->nullable()->change();
            $table->string('writer', 255)->nullable()->change();
            $table->string('country', 255)->nullable()->change();
            $table->string('language', 255)->nullable()->change();
        });
    }
};
