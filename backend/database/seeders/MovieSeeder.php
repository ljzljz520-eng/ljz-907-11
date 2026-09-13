<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class MovieSeeder extends Seeder
{
    /**
     * 清理 UTF-8 字符，移除无效字符
     */
    private function cleanUtf8($value)
    {
        if ($value === null || $value === '') {
            return '';
        }
        
        $value = (string)$value;
        $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        
        if ($cleaned === false) {
            $cleaned = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $cleaned);
        
        if (!mb_check_encoding($cleaned, 'UTF-8')) {
            $cleaned = mb_convert_encoding($cleaned, 'UTF-8', 'UTF-8');
        }
        
        return $cleaned ?: '';
    }

    /**
     * 清理字段，截断过长内容
     */
    private function cleanField($value, $maxLength = null)
    {
        if (empty($value)) {
            return null;
        }
        
        $cleaned = $this->cleanUtf8($value);
        $cleaned = trim($cleaned);
        
        if ($maxLength !== null && mb_strlen($cleaned) > $maxLength) {
            $cleaned = mb_substr($cleaned, 0, $maxLength);
        }
        
        return $cleaned ?: null;
    }

    /**
     * 从可能包含完整页面内容的字段中提取上映日期
     */
    private function extractReleaseDate($raw)
    {
        if (empty($raw)) {
            return null;
        }

        if (mb_strlen($raw) < 100) {
            return $this->cleanField($raw, 255);
        }

        if (preg_match('/(\d{4}-\d{2}-\d{2}(?:\([^)]+\))?)/', $raw, $matches)) {
            return $this->cleanField($matches[1], 255);
        }
        
        if (preg_match('/(\d{4}(?:\([^)]+\))?)/', $raw, $matches)) {
            return $this->cleanField($matches[1], 255);
        }

        return $this->cleanField($raw, 255);
    }

    /**
     * 从可能包含完整页面内容的字段中提取导演
     */
    private function extractDirector($raw)
    {
        if (empty($raw)) {
            return null;
        }

        if (mb_strlen($raw) < 200) {
            return $this->cleanField($raw, 512);
        }

        if (preg_match('/[导导][\s演演]*[:：]?\s*([^\n◎]+)/u', $raw, $matches)) {
            $director = trim($matches[1]);
            $director = preg_replace('/[\n\r◎]+/', ' / ', $director);
            return $this->cleanField($director, 512);
        }

        return $this->cleanField($raw, 512);
    }

    /**
     * 从可能包含完整页面内容的字段中提取产地
     */
    private function extractCountry($raw)
    {
        if (empty($raw)) {
            return null;
        }

        if (mb_strlen($raw) < 200) {
            return $this->cleanField($raw, 512);
        }

        if (preg_match('/[产产][\s地地]*[:：]?\s*([^\n◎]+)/u', $raw, $matches)) {
            $country = trim($matches[1]);
            $country = preg_replace('/[\n\r◎]+/', ' / ', $country);
            return $this->cleanField($country, 512);
        }

        return $this->cleanField($raw, 512);
    }

    /**
     * 从可能包含完整页面内容的字段中提取语言
     */
    private function extractLanguage($raw)
    {
        if (empty($raw)) {
            return null;
        }

        if (mb_strlen($raw) < 200) {
            return $this->cleanField($raw, 512);
        }

        if (preg_match('/[语语][\s言言]*[:：]?\s*([^\n◎]+)/u', $raw, $matches)) {
            $language = trim($matches[1]);
            $language = preg_replace('/[\n\r◎]+/', ' / ', $language);
            return $this->cleanField($language, 512);
        }

        return $this->cleanField($raw, 512);
    }

    /**
     * 提取和验证评分值
     */
    private function extractRating($raw)
    {
        if (empty($raw)) {
            return 0;
        }

        $raw = trim($raw);
        
        if (preg_match('/^(19|20)\d{2}$/', $raw)) {
            return 0;
        }
        
        if (preg_match('/(\d+\.?\d*)/', $raw, $matches)) {
            $rating = (float)$matches[1];
            
            if (preg_match('/(\d+\.?\d*)\s*\/\s*10/i', $raw, $matches)) {
                $rating = (float)$matches[1];
            }
            
            if ($rating >= 1900 && $rating <= 2100) {
                return 0;
            }
            
            if ($rating < 0) {
                $rating = 0;
            } elseif ($rating > 99.9) {
                $rating = 0;
            }
            
            return round($rating, 1);
        }

        return 0;
    }

    public function run(): void
    {
        // 清空现有数据（作为初始数据）
        $this->command->info("Clearing existing movies...");
        Movie::truncate();
        $this->command->info("Existing movies cleared.");
        
        // CSV 文件路径（容器内的路径）
        $csvPath = storage_path('movies_crawled.csv');
        
        if (!File::exists($csvPath)) {
            $this->command->error("CSV file not found: {$csvPath}");
            return;
        }

        $this->command->info("Reading CSV file: {$csvPath}");

        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            $this->command->error("Cannot read CSV file");
            return;
        }

        // 读取 BOM（如果存在）
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            $this->command->error("Empty CSV file");
            return;
        }

        // 清理 header
        $header = array_map([$this, 'cleanUtf8'], $header);
        $header = array_map(function($h) {
            return strtolower(trim($h));
        }, $header);

        // 列映射
        $map = [
            'title' => array_search('title', $header),
            'translated_title' => array_search('translated_title', $header),
            'year' => array_search('year', $header),
            'director' => array_search('director', $header),
            'writer' => array_search('writer', $header),
            'actors' => array_search('actors', $header),
            'release_date' => array_search('release_date', $header),
            'country' => array_search('country', $header),
            'language' => array_search('language', $header),
            'runtime' => array_search('runtime', $header),
            'genre' => array_search('genre', $header),
            'rating' => array_search('rating', $header),
            'imdb_rating' => array_search('imdb_rating', $header),
            'imdb_link' => array_search('imdb_link', $header),
            'douban_link' => array_search('douban_link', $header),
            'poster_url' => array_search('poster_url', $header),
            'description' => array_search('description', $header),
            'awards' => array_search('awards', $header),
            'screenshots' => array_search('screenshots', $header),
        ];

        if ($map['title'] === false || $map['year'] === false) {
            fclose($handle);
            $this->command->error('CSV must contain "title" and "year" columns');
            return;
        }

        $successCount = 0;
        $errorCount = 0;
        $rowNumber = 1;

        DB::beginTransaction();

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                
                // Skip empty rows
                if (empty(array_filter($row))) continue;

                // 清理 CSV 行
                $row = array_map([$this, 'cleanUtf8'], $row);

                try {
                    $title = isset($map['title']) && isset($row[$map['title']]) ? $row[$map['title']] : null;
                    $year = isset($map['year']) && isset($row[$map['year']]) ? (int)$row[$map['year']] : null;

                    if (!$title || !$year) {
                        throw new \Exception("Missing title or year");
                    }

                    // 清理和提取各个字段
                    $releaseDateRaw = ($map['release_date'] !== false && isset($row[$map['release_date']])) ? $row[$map['release_date']] : null;
                    $releaseDate = $this->extractReleaseDate($releaseDateRaw);

                    $directorRaw = ($map['director'] !== false && isset($row[$map['director']])) ? $row[$map['director']] : null;
                    $director = $this->extractDirector($directorRaw);

                    $writerRaw = ($map['writer'] !== false && isset($row[$map['writer']])) ? $row[$map['writer']] : null;
                    $writer = $this->cleanField($writerRaw, 512);

                    $actorsRaw = ($map['actors'] !== false && isset($row[$map['actors']])) ? $row[$map['actors']] : null;
                    $actors = $this->cleanField($actorsRaw, 1024);

                    $countryRaw = ($map['country'] !== false && isset($row[$map['country']])) ? $row[$map['country']] : null;
                    $country = $this->extractCountry($countryRaw);

                    $languageRaw = ($map['language'] !== false && isset($row[$map['language']])) ? $row[$map['language']] : null;
                    $language = $this->extractLanguage($languageRaw);

                    $data = [
                        'title' => $this->cleanField(trim($title), 255),
                        'translated_title' => $this->cleanField(($map['translated_title'] !== false && isset($row[$map['translated_title']])) ? $row[$map['translated_title']] : null, 255),
                        'year' => $year,
                        'director' => $director,
                        'writer' => $writer,
                        'actors' => $actors,
                        'release_date' => $releaseDate,
                        'country' => $country,
                        'language' => $language,
                        'runtime' => $this->cleanField(($map['runtime'] !== false && isset($row[$map['runtime']])) ? $row[$map['runtime']] : null, 50),
                        'genre' => $this->cleanField(($map['genre'] !== false && isset($row[$map['genre']])) ? $row[$map['genre']] : null, 255),
                        'rating' => $this->extractRating(($map['rating'] !== false && isset($row[$map['rating']])) ? $row[$map['rating']] : null),
                        'imdb_rating' => $this->cleanField(($map['imdb_rating'] !== false && isset($row[$map['imdb_rating']])) ? $row[$map['imdb_rating']] : null, 50),
                        'imdb_link' => $this->cleanField(($map['imdb_link'] !== false && isset($row[$map['imdb_link']])) ? $row[$map['imdb_link']] : null, 512),
                        'douban_link' => $this->cleanField(($map['douban_link'] !== false && isset($row[$map['douban_link']])) ? $row[$map['douban_link']] : null, 512),
                        'poster_url' => $this->cleanField(($map['poster_url'] !== false && isset($row[$map['poster_url']])) ? $row[$map['poster_url']] : null, 1024),
                        'description' => $this->cleanField(($map['description'] !== false && isset($row[$map['description']])) ? $row[$map['description']] : null, null),
                        'awards' => $this->cleanField(($map['awards'] !== false && isset($row[$map['awards']])) ? $row[$map['awards']] : null, null),
                        'screenshots' => ($map['screenshots'] !== false && isset($row[$map['screenshots']])) ? $row[$map['screenshots']] : null,
                    ];

                    Movie::updateOrCreate(
                        ['title' => $data['title'], 'year' => $data['year']],
                        $data
                    );

                    $successCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->command->warn("Row {$rowNumber}: " . $e->getMessage());
                }
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            fclose($handle);
            $this->command->error('Import failed: ' . $e->getMessage());
            return;
        }

        fclose($handle);

        $this->command->info("Import completed!");
        $this->command->info("  - Successfully imported: {$successCount}");
        $this->command->info("  - Failed: {$errorCount}");
    }
}
