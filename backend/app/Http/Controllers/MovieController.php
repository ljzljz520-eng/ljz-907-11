<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MovieController extends Controller
{
    /**
     * 预览阶段最多解析的行数，避免超大文件占用过多内存
     */
    const PREVIEW_MAX_ROWS = 5000;

    /**
     * 预览结果临时文件的有效期（分钟）
     */
    const TOKEN_TTL_MINUTES = 60;

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

    /**
     * 第一步：上传 CSV 并生成校验预览。
     *
     * 此接口绝不写入 movies 表，只：
     *  1. 解析并清洗 CSV；
     *  2. 逐行标出错误（重复片名 / 年份格式错误 / 缺少分类 等）；
     *  3. 把解析结果暂存到本地临时文件，返回 token 供“确认导入”使用。
     */
    public function preview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:51200', // 50MB max
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        $file = $request->file('file');

        $parsed = $this->parseCsv($file->getRealPath());
        if (isset($parsed['error'])) {
            return response()->json(['error' => $parsed['error']], 400);
        }

        $rows = $parsed['rows'];

        // 统计文件内同名片名（大小写不敏感、去除首尾空格）
        $titleCounts = [];
        foreach ($rows as $row) {
            $key = $this->titleKey($row['data']['title']);
            if ($key === '') {
                continue;
            }
            $titleCounts[$key] = ($titleCounts[$key] ?? 0) + 1;
        }

        // 批量查询数据库中已存在的同名片名
        $existingTitles = $this->findExistingTitles(array_keys($titleCounts));

        $validCount = 0;
        $invalidCount = 0;
        foreach ($rows as &$row) {
            $errors = [];
            $warnings = [];

            $data = $row['data'];
            $raw = $row['raw'];

            // 1) 片名校验
            if ($data['title'] === null || $data['title'] === '') {
                $errors[] = '缺少片名';
            } else {
                $key = $this->titleKey($data['title']);
                if (($titleCounts[$key] ?? 0) > 1) {
                    // 与文件内其它行片名重复
                    $errors[] = '片名重复（文件内）';
                }
                if (isset($existingTitles[$key])) {
                    // 数据库中已有同名记录，确认导入时会更新而非新增 —— 仅作提示
                    $warnings[] = '片名已存在于片库，确认后将更新该记录';
                }
            }

            // 2) 年份校验：必须是 4 位数字（1888 ~ 当前年+1），禁止 2024.0、二〇二四 等写法
            $yearRaw = isset($raw['year']) ? trim((string) $raw['year']) : '';
            if ($yearRaw === '') {
                $errors[] = '缺少年份';
            } elseif (!preg_match('/^\d{4}$/', $yearRaw)
                || (int) $yearRaw < 1888
                || (int) $yearRaw > (int) date('Y') + 1) {
                $errors[] = '年份格式错误（应为 4 位数字年份）';
            }

            // 3) 分类校验（必填）
            if ($data['genre'] === null || $data['genre'] === '') {
                $errors[] = '缺少分类';
            }

            $row['errors'] = $errors;
            $row['warnings'] = $warnings;
            $row['valid'] = empty($errors);

            if ($row['valid']) {
                $validCount++;
            } else {
                $invalidCount++;
            }
        }
        unset($row);

        if ($validCount === 0) {
            // 没有任何可导入的行，无需暂存
            return response()->json([
                'status' => 'preview',
                'token' => null,
                'total' => count($rows),
                'valid_count' => 0,
                'invalid_count' => $invalidCount,
                'columns' => $parsed['columns'],
                'rows' => $rows,
            ]);
        }

        // 暂存解析结果，等待管理员确认。不使用 session/缓存，避免多实例部署时丢失。
        $token = bin2hex(random_bytes(16));
        $payload = [
            'created_at' => time(),
            'rows' => $rows,
        ];
        Storage::disk('local')->put($this->storedCsvPath($token), json_encode($payload, JSON_UNESCAPED_UNICODE));

        return response()->json([
            'status' => 'preview',
            'token' => $token,
            'total' => count($rows),
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
            'columns' => $parsed['columns'],
            'rows' => $rows,
        ]);
    }

    /**
     * 第二步：管理员确认后，把预览中通过校验的行写入 MySQL。
     *
     * 关键点：
     *  - 只写入 preview 阶段标记为 valid 的行；
     *  - 整个写入包在数据库事务中，任何一行失败都会整体回滚，
     *    绝不清空/破坏此前已经存在于片库中的老数据；
     *  - 服务端重新校验 token 与有效期，并对每行再次做硬校验，不信任前端。
     */
    public function confirmImport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string|size:32',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => '缺少有效的导入凭证，请重新上传预览'], 400);
        }

        $token = $request->input('token');
        $path = $this->storedCsvPath($token);

        if (!Storage::disk('local')->exists($path)) {
            return response()->json(['error' => '预览结果不存在或已过期，请重新上传 CSV'], 404);
        }

        $payload = json_decode(Storage::disk('local')->get($path), true);
        if (!is_array($payload) || !isset($payload['created_at'], $payload['rows'])) {
            Storage::disk('local')->delete($path);
            return response()->json(['error' => '预览结果已损坏，请重新上传 CSV'], 500);
        }

        if (time() - $payload['created_at'] > self::TOKEN_TTL_MINUTES * 60) {
            Storage::disk('local')->delete($path);
            return response()->json(['error' => '预览结果已过期（超过 ' . self::TOKEN_TTL_MINUTES . ' 分钟），请重新上传 CSV'], 410);
        }

        // 清理过期的暂存文件（顺带维护，不影响本次导入）
        $this->pruneStaleTokens();

        $validRows = array_filter($payload['rows'], function ($row) {
            return !empty($row['valid']) && is_array($row['data'] ?? null);
        });

        if (empty($validRows)) {
            Storage::disk('local')->delete($path);
            return response()->json(['error' => '没有可导入的有效数据'], 400);
        }

        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            // 事务期间先锁定可能受影响的已有记录，避免并发导入互相覆盖
            $titles = array_values(array_map(function ($row) {
                return $row['data']['title'];
            }, $validRows));
            $this->lockExistingTitles($titles);

            foreach ($validRows as $row) {
                try {
                    $data = $this->hardenRowData($row['data']);
                    if ($data === null) {
                        throw new \Exception('数据未通过服务端复核（行号 ' . $row['row_number'] . '）');
                    }

                    Movie::updateOrCreate(
                        ['title' => $data['title'], 'year' => $data['year']],
                        $data
                    );
                    $successCount++;
                } catch (\Exception $e) {
                    $failedCount++;
                    if (count($errors) < 20) {
                        $errors[] = $this->cleanUtf8('第 ' . $row['row_number'] . ' 行：' . $e->getMessage());
                    }
                }
            }

            if ($successCount === 0) {
                // 一行都没写进去：整体回滚，片库保持原样
                DB::rollBack();
                Storage::disk('local')->delete($path);
                return response()->json([
                    'status' => 'failed',
                    'error' => '导入失败：没有任何行写入成功，片库数据保持不变。',
                    'imported' => 0,
                    'failed' => $failedCount,
                    'errors' => $errors,
                ], 422);
            }

            DB::commit();
        } catch (\Exception $e) {
            // 任何意外异常都回滚，保证老数据不被清空/破坏
            DB::rollBack();
            Storage::disk('local')->delete($path);
            return response()->json([
                'status' => 'failed',
                'error' => $this->cleanUtf8('导入失败，所有变更已回滚，已有片库数据未受影响：' . $e->getMessage()),
            ], 500);
        }

        // 导入成功后才删除暂存文件（一个 token 只能成功使用一次）
        Storage::disk('local')->delete($path);

        return response()->json([
            'status' => 'success',
            'imported' => $successCount,
            'failed' => $failedCount,
            'errors' => $errors,
        ]);
    }

    /**
     * 解析 CSV 文件：BOM 处理、表头映射、字段清洗。
     *
     * 返回 ['columns' => [...], 'rows' => [['row_number' => int, 'raw' => [...], 'data' => [...]], ...]]
     * 出错时返回 ['error' => string]
     */
    private function parseCsv($path)
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            return ['error' => '无法读取上传的文件'];
        }

        // 读取 BOM（如果存在）
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return ['error' => 'CSV 文件为空'];
        }

        $header = array_map(function ($h) {
            return strtolower(trim($this->cleanUtf8($h)));
        }, $header);

        // 支持中英文表头
        $aliases = [
            'title'            => ['title', '片名', '电影片名', '电影名', '名称'],
            'translated_title' => ['translated_title', '译名', '又名'],
            'year'             => ['year', '年份'],
            'director'         => ['director', '导演'],
            'writer'           => ['writer', '编剧'],
            'actors'           => ['actors', '主演', '演员'],
            'release_date'     => ['release_date', '上映日期', '上映时间'],
            'country'          => ['country', '产地', '地区', '国家'],
            'language'         => ['language', '语言'],
            'runtime'          => ['runtime', '时长', '片长'],
            'genre'            => ['genre', '分类', '类型', '类别'],
            'rating'           => ['rating', '评分'],
            'imdb_rating'      => ['imdb_rating'],
            'imdb_link'        => ['imdb_link'],
            'douban_link'      => ['douban_link'],
            'poster_url'       => ['poster_url', '海报', '海报地址', '海报url', '海报链接'],
            'description'      => ['description', '简介', '剧情简介'],
            'awards'           => ['awards', '获奖'],
            'screenshots'      => ['screenshots', '剧照'],
        ];

        $map = [];
        $columns = []; // 实际识别到的字段，供前端按列展示
        foreach ($aliases as $field => $names) {
            $index = false;
            foreach ($names as $name) {
                $pos = array_search($name, $header, true);
                if ($pos !== false) {
                    $index = $pos;
                    break;
                }
            }
            $map[$field] = $index;
            if ($index !== false) {
                $columns[$field] = $header[$index];
            }
        }

        if ($map['title'] === false || $map['year'] === false) {
            fclose($handle);
            return ['error' => 'CSV 必须包含 "title/片名" 和 "year/年份" 两列'];
        }

        $rows = [];
        $rowNumber = 1; // 表头是第 1 行
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip empty rows
            if (empty(array_filter($row, function ($v) {
                return trim((string) $v) !== '';
            }))) {
                continue;
            }

            if (count($rows) >= self::PREVIEW_MAX_ROWS) {
                break;
            }

            $row = array_map([$this, 'cleanUtf8'], $row);

            // 保留原始值用于报错信息展示（尤其是年份原始文本）
            $raw = [];
            foreach ($map as $field => $index) {
                $raw[$field] = ($index !== false && isset($row[$index])) ? $row[$index] : null;
            }

            $releaseDate = $this->extractReleaseDate($raw['release_date']);
            $director = $this->extractDirector($raw['director']);

            $data = [
                'title' => $this->cleanField(trim((string) $raw['title']), 255),
                'translated_title' => $this->cleanField($raw['translated_title'], 255),
                // 年份保留原始文本：预览页原样回显，确认阶段才做格式硬校验
                'year' => ($raw['year'] !== null && trim((string) $raw['year']) !== '')
                    ? trim((string) $raw['year'])
                    : null,
                'director' => $director,
                'writer' => $this->cleanField($raw['writer'], 512),
                'actors' => $this->cleanField($raw['actors'], 1024),
                'release_date' => $releaseDate,
                'country' => $this->extractCountry($raw['country']),
                'language' => $this->extractLanguage($raw['language']),
                'runtime' => $this->extractRuntime($raw['runtime']),
                'genre' => $this->cleanField($raw['genre'], 255),
                'rating' => $this->extractRating($raw['rating']),
                'imdb_rating' => $this->cleanField($raw['imdb_rating'], 50),
                'imdb_link' => $this->cleanField($raw['imdb_link'], 512),
                'douban_link' => $this->cleanField($raw['douban_link'], 512),
                'poster_url' => $this->extractPosterUrl($raw['poster_url']),
                'description' => $this->cleanField($raw['description'], null),
                'awards' => $this->cleanField($raw['awards'], null),
                'screenshots' => $raw['screenshots'] !== null && $raw['screenshots'] !== ''
                    ? array_values(array_filter(array_map('trim', explode(',', $raw['screenshots']))))
                    : null,
            ];

            $rows[] = [
                'row_number' => $rowNumber,
                'raw' => $raw,
                'data' => $data,
                'errors' => [],
                'warnings' => [],
                'valid' => false,
            ];
        }

        fclose($handle);

        if (empty($rows)) {
            return ['error' => 'CSV 中没有任何数据行'];
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * 确认导入前的服务端复核：只接受预览阶段验证过的字段，
     * 返回可以直接入库的数组；不合法则返回 null。
     */
    private function hardenRowData($data)
    {
        if (!is_array($data)) {
            return null;
        }

        $title = isset($data['title']) ? trim((string) $data['title']) : '';
        $yearRaw = isset($data['year']) ? trim((string) $data['year']) : '';
        $genre = isset($data['genre']) ? trim((string) $data['genre']) : '';

        if ($title === '' || $genre === '') {
            return null;
        }
        if (!preg_match('/^\d{4}$/', $yearRaw)
            || (int) $yearRaw < 1888
            || (int) $yearRaw > (int) date('Y') + 1) {
            return null;
        }

        $data['title'] = mb_substr($title, 0, 255);
        $data['genre'] = mb_substr($genre, 0, 255);
        $data['year'] = (int) $yearRaw;

        // 只保留模型允许的字段，防止前端/暂存内容夹带其它键
        return collect($data)->only([
            'title', 'translated_title', 'year', 'director', 'writer', 'actors',
            'release_date', 'country', 'language', 'runtime', 'genre', 'rating',
            'imdb_rating', 'imdb_link', 'douban_link', 'poster_url',
            'description', 'awards', 'screenshots',
        ])->all();
    }

    /**
     * 片名归一化：用于重复检测（大小写不敏感、去除首尾空格）
     */
    private function titleKey($title)
    {
        if ($title === null) {
            return '';
        }
        return mb_strtolower(trim((string) $title), 'UTF-8');
    }

    /**
     * 在数据库中查找已经存在的同名片名，返回 [归一化片名 => true]
     */
    private function findExistingTitles(array $keys)
    {
        $result = [];
        if (empty($keys)) {
            return $result;
        }

        foreach (array_chunk($keys, 500) as $chunk) {
            // MySQL 默认 collation 通常本身就是大小写不敏感的，这里用 lower 兜底
            Movie::whereIn(DB::raw('LOWER(TRIM(title))'), $chunk)
                ->select('title')
                ->chunk(1000, function ($movies) use (&$result) {
                    foreach ($movies as $movie) {
                        $result[$this->titleKey($movie->title)] = true;
                    }
                });
        }

        return $result;
    }

    /**
     * 锁定这些片名对应的已有行（SELECT ... FOR UPDATE），配合事务防止并发覆盖
     */
    private function lockExistingTitles(array $titles)
    {
        foreach (array_chunk($titles, 500) as $chunk) {
            Movie::whereIn('title', $chunk)->lockForUpdate()->get();
        }
    }

    private function storedCsvPath($token)
    {
        return 'csv_preview/' . $token . '.json';
    }

    /**
     * 清理超过有效期的预览暂存文件
     */
    private function pruneStaleTokens()
    {
        $disk = Storage::disk('local');
        $dir = 'csv_preview';
        if (!$disk->exists($dir)) {
            return;
        }
        foreach ($disk->files($dir) as $file) {
            if (time() - $disk->lastModified($file) > self::TOKEN_TTL_MINUTES * 60) {
                $disk->delete($file);
            }
        }
    }

    /**
     * 清洗时长：兼容 "120分钟"、"120 min"、"02:00:00" 等写法；空值返回 null
     */
    private function extractRuntime($raw)
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        // H:MM:SS 形式
        if (preg_match('/^(\d+):(\d{1,2}):(\d{1,2})$/', $raw, $m)) {
            $minutes = (int) $m[1] * 60 + (int) $m[2];
            return $minutes . '分钟';
        }

        // 纯数字或 "120分钟" / "120 min"
        if (preg_match('/(\d+)\s*(?:分钟|min|minutes)?/iu', $raw, $m)) {
            return ((int) $m[1] > 0) ? ((int) $m[1] . '分钟') : null;
        }

        return $this->cleanField($raw, 50);
    }

    /**
     * 清洗海报地址：从脏文本中提取 http(s) 链接
     */
    private function extractPosterUrl($raw)
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('#https?://[^\s,，"\']+#i', $raw, $m)) {
            return $this->cleanField($m[0], 1024);
        }

        return $this->cleanField($raw, 1024);
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