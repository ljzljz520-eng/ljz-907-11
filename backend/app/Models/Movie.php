<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Movie extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'translated_title',
        'director',
        'writer',
        'actors',
        'year',
        'release_date',
        'country',
        'language',
        'runtime',
        'genre',
        'rating',
        'imdb_rating',
        'imdb_link',
        'douban_link',
        'poster_url',
        'description',
        'awards',
        'screenshots',
    ];

    protected $casts = [
        'year' => 'integer',
        'rating' => 'decimal:1',
        'screenshots' => 'array',
    ];
}