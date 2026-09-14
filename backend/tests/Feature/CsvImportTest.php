<?php

namespace Tests\Feature;

use App\Models\Movie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function csvUpload(string $content): UploadedFile
    {
        $path = sys_get_temp_dir() . '/import_' . uniqid() . '.csv';
        file_put_contents($path, $content);
        return new UploadedFile($path, 'movies.csv', 'text/csv', null, true);
    }

    public function test_preview_flags_duplicate_titles_bad_years_and_missing_genre(): void
    {
        $csv = implode("\n", [
            'title,year,genre,director,runtime,poster_url',
            '高山下的花环,1984,剧情,谢晋,120,https://example.com/a.jpg',
            '高山下的花环,1984,战争,谢晋,120,https://example.com/b.jpg', // 文件内片名重复
            '过年,199x,剧情,黄健中,105,https://example.com/c.jpg',       // 年份格式错误
            '凤凰琴,1994,,何群,90,https://example.com/d.jpg',            // 缺少分类
            '一个都不能少,1999,剧情,张艺谋,106,',                          // 有效行
        ]);

        $res = $this->postJson('/api/movies/import/preview', [
            'file' => $this->csvUpload($csv),
        ]);

        $res->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonPath('valid_count', 1)
            ->assertJsonPath('invalid_count', 4);

        $rows = $res->json('rows');
        $this->assertTrue($rows[0]['valid'] === false);
        $this->assertContains('片名重复（文件内）', $rows[0]['errors']);
        $this->assertContains('片名重复（文件内）', $rows[1]['errors']);
        $this->assertContains('年份格式错误（应为 4 位数字年份）', $rows[2]['errors']);
        $this->assertContains('缺少分类', $rows[3]['errors']);
        $this->assertTrue($rows[4]['valid']);

        // 预览阶段绝不写库
        $this->assertEquals(0, Movie::count());
    }

    public function test_preview_warns_when_title_already_exists_in_database(): void
    {
        Movie::create(['title' => '地道战', 'year' => 1965, 'genre' => '战争']);

        $csv = implode("\n", [
            'title,year,genre',
            '地道战,1965,战争', // 同名记录已存在 -> 警告但仍有效（确认时更新）
            '平原游击队,1974,战争',
        ]);

        $res = $this->postJson('/api/movies/import/preview', ['file' => $this->csvUpload($csv)]);
        $res->assertOk()->assertJsonPath('valid_count', 2);

        $warnings = $res->json('rows.0.warnings');
        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, '已存在')));
    }

    public function test_confirm_imports_only_valid_rows_and_keeps_old_data_on_partial_failure(): void
    {
        // 片库中已有老数据
        Movie::create(['title' => '老电影', 'year' => 2000, 'genre' => '剧情']);

        $csv = implode("\n", [
            'title,year,genre,runtime',
            '新电影A,2001,剧情,100',  // 有效
            '新电影B,2002,剧情,110',  // 有效
            '坏年份电影,20xx,剧情,90', // 无效：年份错误
            '缺分类电影,2003,,90',     // 无效：缺分类
        ]);

        $preview = $this->postJson('/api/movies/import/preview', ['file' => $this->csvUpload($csv)]);
        $token = $preview->json('token');
        $this->assertNotEmpty($token);

        $res = $this->postJson('/api/movies/import/confirm', ['token' => $token]);
        $res->assertOk()->assertJsonPath('imported', 2);

        $this->assertDatabaseHas('movies', ['title' => '老电影', 'year' => 2000]);
        $this->assertDatabaseHas('movies', ['title' => '新电影A', 'year' => 2001]);
        $this->assertDatabaseHas('movies', ['title' => '新电影B', 'year' => 2002]);
        $this->assertDatabaseMissing('movies', ['title' => '坏年份电影']);
        $this->assertDatabaseMissing('movies', ['title' => '缺分类电影']);

        // token 只能使用一次
        $this->postJson('/api/movies/import/confirm', ['token' => $token])
             ->assertStatus(404);
    }

    public function test_confirm_rolls_back_when_no_row_could_be_written(): void
    {
        Movie::create(['title' => '老电影', 'year' => 2000, 'genre' => '剧情']);
        $before = Movie::count();

        // 所有行在服务端复核时失效（暂存内容被损坏的极端情况也不该破坏老数据）
        $csv = "title,year,genre\n好电影,2001,剧情";
        $preview = $this->postJson('/api/movies/import/preview', ['file' => $this->csvUpload($csv)]);
        $token = $preview->json('token');

        $disk = Storage::disk('local');
        $previewPath = collect($disk->files('csv_preview'))->first();
        $this->assertNotEmpty($previewPath, '暂存文件应已写入 csv_preview 目录');

        $payload = json_decode($disk->get($previewPath), true);
        foreach ($payload['rows'] as &$row) {
            $row['data']['year'] = 'abcd'; // 篡改暂存数据
        }
        unset($row);
        $disk->put($previewPath, json_encode($payload, JSON_UNESCAPED_UNICODE));

        $res = $this->postJson('/api/movies/import/confirm', ['token' => $token]);
        $res->assertStatus(422);

        // 老数据原封不动
        $this->assertEquals($before, Movie::count());
        $this->assertDatabaseHas('movies', ['title' => '老电影', 'year' => 2000]);
    }

    public function test_expired_or_unknown_token_is_rejected(): void
    {
        $this->postJson('/api/movies/import/confirm', ['token' => str_repeat('a', 32)])
             ->assertStatus(404);
    }
}
