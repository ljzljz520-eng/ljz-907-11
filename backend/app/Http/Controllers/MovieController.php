<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MovieController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 18);
        $search = $request->input('search');

        $query = Movie::query();

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('director', 'like', "%{$search}%")
                  ->orWhere('actors', 'like', "%{$search}%");
            });
        }

        $movies = $query->orderBy('created_at', 'desc')
                        ->orderBy('id', 'desc')
                        ->paginate($perPage);

        return response()->json($movies);
    }

    public function show($id)
    {
        $movie = Movie::find($id);
        if (!$movie) {
            return response()->json(['error' => 'Movie not found'], 404);
        }
        return response()->json($movie);
    }

    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:51200', // 50MB max
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        $file = $request->file('file');
        $path = $file->getRealPath();

        // 尝试以 UTF-8 编码打开文件
        $handle = fopen($path, 'r');
        if (!$handle) {
            return response()->json(['error' => 'Cannot read file'], 500);
        }

        // 读取 BOM（如果存在）
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle); // Read header
        if (!$header) {
            fclose($handle);
            return response()->json(['error' => 'Empty CSV file'], 400);
        }

        // 清理 header 中的无效 UTF-8 字符
        $header = array_map([$this, 'cleanUtf8'], $header);

        // Normalize header to lowercase and trim
        $header = array_map(function($h) {
            return strtolower(trim($h));
        }, $header);

        // Required columns mapping (flexible matching)
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
            return response()->json(['error' => 'CSV must contain "title" and "year" columns'], 400);
        }

        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        $rowNumber = 1;

        DB::beginTransaction();

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                
                // Skip empty rows
                if (empty(array_filter($row))) continue;

                // 清理 CSV 行中的无效 UTF-8 字符
                $row = array_map([$this, 'cleanUtf8'], $row);

                try {
                    $title = isset($map['title']) && isset($row[$map['title']]) ? $row[$map['title']] : null;
                    $year = isset($map['year']) && isset($row[$map['year']]) ? (int)$row[$map['year']] : null;

                    if (!$title || !$year) {
                        throw new \Exception("Missing title or year");
                    }

                    // 清理和提取 release_date - 从可能包含完整页面内容的字段中提取日期
                    $releaseDateRaw = ($map['release_date'] !== false && isset($row[$map['release_date']])) ? $row[$map['release_date']] : null;
                    $releaseDate = $this->extractReleaseDate($releaseDateRaw);

                    // 清理和提取 director - 从可能包含完整页面内容的字段中提取导演
                    $directorRaw = ($map['director'] !== false && isset($row[$map['director']])) ? $row[$map['director']] : null;
                    $director = $this->extractDirector($directorRaw);

                    // 清理和提取其他字段
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
                        'screenshots' => ($map['screenshots'] !== false && isset($row[$map['screenshots']])) ? explode(',', $row[$map['screenshots']]) : null,
                    ];

                    Movie::updateOrCreate(
                        ['title' => $data['title'], 'year' => $data['year']],
                        $data
                    );

                    $successCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    if (count($errors) < 10) { // Limit error reporting
                        $errorMsg = $this->cleanUtf8("Row {$rowNumber}: " . $e->getMessage());
                        $errors[] = $errorMsg;
                    }
                }
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            fclose($handle);
            $errorMsg = $this->cleanUtf8('Import failed: ' . $e->getMessage());
            return response()->json(['error' => $errorMsg], 500);
        }

        fclose($handle);

        // 清理错误信息中的无效 UTF-8 字符
        $cleanedErrors = array_map([$this, 'cleanUtf8'], $errors);

        return response()->json([
            'status' => 'success',
            'imported' => $successCount,
            'failed' => $errorCount,
            'errors' => $cleanedErrors
        ]);
    }

    /**
     * 清理 UTF-8 字符，移除无效字符
     */
    private function cleanUtf8($value)
    {
        if ($value === null || $value === '') {
            return '';
        }
        
        // 转换为字符串
        $value = (string)$value;
        
        // 使用 iconv 移除无效的 UTF-8 字符
        $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        
        if ($cleaned === false) {
            // 如果 iconv 失败，使用 mb_convert_encoding
            $cleaned = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        
        // 移除控制字符（保留换行符和制表符）
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $cleaned);
        
        // 验证是否为有效的 UTF-8
        if (!mb_check_encoding($cleaned, 'UTF-8')) {
            // 如果不是有效的 UTF-8，重新转换
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
        
        // 先清理 UTF-8
        $cleaned = $this->cleanUtf8($value);
        $cleaned = trim($cleaned);
        
        // 如果指定了最大长度，截断
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

        // 如果字段很短（小于100字符），可能是正确的日期格式，直接返回
        if (mb_strlen($raw) < 100) {
            return $this->cleanField($raw, 255);
        }

        // 尝试从文本中提取日期格式
        // 匹配格式：2025-11-26(美国/中国大陆) 或 2025-11-26 或 2026(中国大陆)
        if (preg_match('/(\d{4}-\d{2}-\d{2}(?:\([^)]+\))?)/', $raw, $matches)) {
            return $this->cleanField($matches[1], 255);
        }
        
        // 匹配格式：2026(中国大陆)
        if (preg_match('/(\d{4}(?:\([^)]+\))?)/', $raw, $matches)) {
            return $this->cleanField($matches[1], 255);
        }

        // 如果找不到日期，返回前255字符
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

        // 如果字段很短（小于200字符），可能是正确的导演名，直接返回
        if (mb_strlen($raw) < 200) {
            return $this->cleanField($raw, 512);
        }

        // 尝试从文本中提取导演信息
        // 匹配格式：◎导　　演　XXX 或 导演: XXX
        if (preg_match('/[导导][\s演演]*[:：]?\s*([^\n◎]+)/u', $raw, $matches)) {
            $director = trim($matches[1]);
            // 移除可能的换行符和特殊字符
            $director = preg_replace('/[\n\r◎]+/', ' / ', $director);
            return $this->cleanField($director, 512);
        }

        // 如果找不到，返回前512字符
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

        // 如果字段很短（小于200字符），可能是正确的产地，直接返回
        if (mb_strlen($raw) < 200) {
            return $this->cleanField($raw, 512);
        }

        // 尝试从文本中提取产地信息
        // 匹配格式：◎产　　地　XXX 或 产地: XXX
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

        // 如果字段很短（小于200字符），可能是正确的语言，直接返回
        if (mb_strlen($raw) < 200) {
            return $this->cleanField($raw, 512);
        }

        // 尝试从文本中提取语言信息
        // 匹配格式：◎语　　言　XXX 或 语言: XXX
        if (preg_match('/[语语][\s言言]*[:：]?\s*([^\n◎]+)/u', $raw, $matches)) {
            $language = trim($matches[1]);
            $language = preg_replace('/[\n\r◎]+/', ' / ', $language);
            return $this->cleanField($language, 512);
        }

        return $this->cleanField($raw, 512);
    }

    /**
     * 提取和验证评分值
     * 数据库字段是 decimal(3,1)，范围是 0.0 到 99.9
     * 通常评分是 0-10，但我们需要确保不超过数据库限制
     */
    private function extractRating($raw)
    {
        if (empty($raw)) {
            return 0;
        }

        // 清理字符串
        $raw = trim($raw);
        
        // 如果值看起来像是年份（4位数字，1900-2100之间），返回 0
        if (preg_match('/^(19|20)\d{2}$/', $raw)) {
            return 0;
        }
        
        // 尝试提取数字（可能包含 "6.9/10" 这样的格式）
        if (preg_match('/(\d+\.?\d*)/', $raw, $matches)) {
            $rating = (float)$matches[1];
            
            // 如果值看起来像是 0-10 的评分，但实际值很大（可能是年份或其他数字）
            // 检查是否可能是 "6.9/10" 格式，如果是，使用第一个数字
            if (preg_match('/(\d+\.?\d*)\s*\/\s*10/i', $raw, $matches)) {
                $rating = (float)$matches[1];
            }
            
            // 如果值看起来像是年份（1900-2100），返回 0
            if ($rating >= 1900 && $rating <= 2100) {
                return 0;
            }
            
            // 确保值在有效范围内（0.0 到 99.9）
            if ($rating < 0) {
                $rating = 0;
            } elseif ($rating > 99.9) {
                // 如果值超过 99.9，可能是错误的数据（如年份），设为 0
                $rating = 0;
            }
            
            // 四舍五入到小数点后1位
            return round($rating, 1);
        }

        // 如果无法提取数字，返回 0
        return 0;
    }

    /**
     * 图片代理接口 - 用于解决CORS和403问题
     */
    public function proxyImage(Request $request)
    {
        $url = $request->input('url');
        
        if (!$url) {
            return response()->json(['error' => 'URL parameter is required'], 400);
        }

        // 验证URL格式
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['error' => 'Invalid URL format'], 400);
        }

        // 只允许特定域名的图片
        $allowedDomains = [
            'playwoool.com',
            'doubanio.com',
            'imdb.com',
            'themoviedb.org',
        ];
        
        $urlHost = parse_url($url, PHP_URL_HOST);
        $isAllowed = false;
        foreach ($allowedDomains as $domain) {
            if (strpos($urlHost, $domain) !== false) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            return response()->json(['error' => 'Domain not allowed'], 403);
        }

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get($url, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'Referer' => parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST),
                ],
                'timeout' => 10,
            ]);

            return response($response->getBody(), 200)
                ->header('Content-Type', $response->getHeader('Content-Type')[0] ?? 'image/jpeg')
                ->header('Cache-Control', 'public, max-age=31536000');
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch image: ' . $e->getMessage()], 500);
        }
    }
}