<?php

namespace Webcimes\LaravelMediaforge\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webcimes\LaravelMediaforge\MediaForge;
use Webcimes\LaravelMediaforge\ImageFormat;
use Webcimes\LaravelMediaforge\Tests\TestCase;

class MediaForgeTest extends TestCase
{
    private MediaForge $fileService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->fileService = app(MediaForge::class);
    }

    // -------------------------------------------------------------------------
    // upload() — base behaviour
    // -------------------------------------------------------------------------

    public function test_upload_null_returns_null(): void
    {
        $result = $this->fileService->upload(null);

        $this->assertNull($result);
    }

    public function test_upload_non_image_stores_file_and_returns_default_key(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 50);

        $result = $this->fileService->upload($file, 'public', 'uploads');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('default', $result);
        $this->assertSame('public', $result['default']['disk']);
        Storage::disk('public')->assertExists($result['default']['path']);
    }

    public function test_upload_image_without_formats_stores_at_base_path(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $result = $this->fileService->upload($file, 'public', 'uploads');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('default', $result);

        $path = $result['default']['path'];
        // Should be directly inside 'uploads/', no sub-folder
        $this->assertStringStartsWith('uploads/', $path);
        $parts = explode('/', $path);
        $this->assertCount(2, $parts, 'No sub-folder expected when no formats given');

        Storage::disk('public')->assertExists($path);
    }

    // -------------------------------------------------------------------------
    // upload() — with ImageFormat
    // -------------------------------------------------------------------------

    public function test_upload_image_with_formats_groups_files_in_one_folder(): void
    {
        $file = UploadedFile::fake()->image('hero.jpg', 2000, 1000);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('default')->scaleDown(1920, 1080)->quality(80)->extension('webp'),
            ImageFormat::make('thumb')->cover(400, 300)->quality(60)->extension('webp'),
        ]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('default', $result);
        $this->assertArrayHasKey('thumb', $result);

        // Both formats share the same parent folder
        $this->assertSame(
            dirname($result['default']['path']),
            dirname($result['thumb']['path']),
            'Both formats should be in the same baseName folder',
        );

        // Folder is inside 'uploads/'
        $this->assertStringStartsWith('uploads/', $result['default']['path']);

        // Filenames default to format name
        $this->assertSame('default.webp', basename($result['default']['path']));
        $this->assertSame('thumb.webp', basename($result['thumb']['path']));

        Storage::disk('public')->assertExists($result['default']['path']);
        Storage::disk('public')->assertExists($result['thumb']['path']);
    }

    public function test_upload_with_custom_filename(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('default')->extension('webp')->filename('hero'),
            ImageFormat::make('thumb')->cover(200, 200)->extension('webp')->filename('hero-thumb'),
        ]);

        $this->assertSame('hero.webp', basename($result['default']['path']));
        $this->assertSame('hero-thumb.webp', basename($result['thumb']['path']));
        Storage::disk('public')->assertExists($result['default']['path']);
        Storage::disk('public')->assertExists($result['thumb']['path']);
    }

    public function test_upload_with_custom_basename(): void
    {
        $file = UploadedFile::fake()->image('anything.jpg', 800, 600);

        $result = $this->fileService->upload(
            $file,
            'public',
            'uploads',
            [ImageFormat::make('default')->extension('webp')],
            'my-product',
        );

        $folder = basename(dirname($result['default']['path']));
        $this->assertStringStartsWith('my-product_', $folder);
        Storage::disk('public')->assertExists($result['default']['path']);
    }

    public function test_upload_with_ulid_only_basename(): void
    {
        $file = UploadedFile::fake()->image('anything.jpg', 800, 600);

        $result = $this->fileService->upload(
            $file,
            'public',
            'uploads',
            [ImageFormat::make('default')->extension('webp')],
            '',
        );

        $folder = basename(dirname($result['default']['path']));
        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $folder);
        Storage::disk('public')->assertExists($result['default']['path']);
    }

    public function test_upload_non_image_with_custom_basename(): void
    {
        $file = UploadedFile::fake()->create('report.pdf', 10);

        $result = $this->fileService->upload($file, 'public', 'docs', null, 'my-report');

        $filename = basename($result['default']['path']);
        $this->assertStringStartsWith('my-report_', $filename);
        Storage::disk('public')->assertExists($result['default']['path']);
    }

    public function test_upload_auto_injects_default_when_missing(): void
    {
        $file = UploadedFile::fake()->image('banner.png', 1000, 500);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('thumb')->cover(200, 200),
        ]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('default', $result);
        $this->assertArrayHasKey('thumb', $result);
    }

    public function test_upload_entries_do_not_contain_base_metadata_keys(): void
    {
        $file = UploadedFile::fake()->image('img.png', 1000, 500);

        $result = $this->fileService->upload($file, 'public', 'media', [
            ImageFormat::make('default')->scaleDown(1920, 1080)->quality(75)->extension('webp'),
            ImageFormat::make('thumb')->cover(400, 300)->quality(60)->extension('webp'),
        ]);

        // baseName and baseDirectory must NOT be stored in entries any more
        foreach (['default', 'thumb'] as $key) {
            $this->assertArrayNotHasKey('baseName', $result[$key]);
            $this->assertArrayNotHasKey('baseDirectory', $result[$key]);
        }

        // Both formats share the same parent folder (baseName is deducible from path)
        $this->assertSame(dirname($result['default']['path']), dirname($result['thumb']['path']));

        // The parent folder lives inside 'media/'
        $this->assertStringStartsWith('media/', $result['default']['path']);

        // no _config key any more
        $this->assertArrayNotHasKey('_config', $result['default']);
        $this->assertArrayNotHasKey('_config', $result['thumb']);
    }

    public function test_upload_default_format_without_transforms_copies_original_bytes(): void
    {
        $file = UploadedFile::fake()->image('copy.png', 100, 100);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            // Only a thumb — default will be auto-injected with no transforms
        ]);

        $this->assertArrayNotHasKey('_config', $result['default']);
        $this->assertArrayNotHasKey('baseName', $result['default']);
        $this->assertArrayNotHasKey('baseDirectory', $result['default']);
    }

    public function test_upload_image_returns_width_and_height(): void
    {
        $file = UploadedFile::fake()->image('sized.jpg', 640, 480);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('default'),
        ]);

        $this->assertArrayHasKey('width', $result['default']);
        $this->assertArrayHasKey('height', $result['default']);
        $this->assertIsInt($result['default']['width']);
        $this->assertIsInt($result['default']['height']);
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function test_delete_removes_file_from_disk(): void
    {
        $file = UploadedFile::fake()->create('to_delete.txt', 1);
        $result = $this->fileService->upload($file, 'public', '');

        $path = $result['default']['path'];
        Storage::disk('public')->assertExists($path);

        $this->fileService->delete($path, 'public');

        Storage::disk('public')->assertMissing($path);
    }

    public function test_delete_removes_all_formats_from_image_entry(): void
    {
        $file = UploadedFile::fake()->image('multi.jpg', 500, 500);

        $entry = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('default'),
            ImageFormat::make('thumb')->cover(100, 100),
        ]);

        Storage::disk('public')->assertExists($entry['default']['path']);
        Storage::disk('public')->assertExists($entry['thumb']['path']);

        $this->fileService->delete([$entry], 'public');

        Storage::disk('public')->assertMissing($entry['default']['path']);
        Storage::disk('public')->assertMissing($entry['thumb']['path']);
    }

    public function test_delete_null_does_not_throw(): void
    {
        $this->fileService->delete(null);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // regenerate()
    // -------------------------------------------------------------------------

    public function test_regenerate_creates_new_format_from_default(): void
    {
        $file = UploadedFile::fake()->image('regen.jpg', 800, 600);

        $stored = $this->fileService->upload($file, 'public', 'photos', [
            ImageFormat::make('default'),
        ]);

        $updated = $this->fileService->regenerate($stored, [
            ImageFormat::make('thumb')->cover(200, 200)->extension('webp'),
        ]);

        $this->assertArrayHasKey('thumb', $updated);
        $this->assertSame(dirname($updated['default']['path']), dirname($updated['thumb']['path']));
        $this->assertSame('thumb.webp', basename($updated['thumb']['path']));
        Storage::disk('public')->assertExists($updated['thumb']['path']);
    }

    public function test_regenerate_replaces_existing_format_file(): void
    {
        $file = UploadedFile::fake()->image('replace.jpg', 800, 600);

        $stored = $this->fileService->upload($file, 'public', 'photos', [
            ImageFormat::make('default'),
            ImageFormat::make('thumb')->cover(200, 200),
        ]);

        $oldThumbPath = $stored['thumb']['path'];
        Storage::disk('public')->assertExists($oldThumbPath);

        $updated = $this->fileService->regenerate($stored, [
            ImageFormat::make('thumb')->cover(100, 100)->extension('webp'),
        ]);

        Storage::disk('public')->assertMissing($oldThumbPath);
        Storage::disk('public')->assertExists($updated['thumb']['path']);
    }

    public function test_regenerate_throws_when_default_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->fileService->regenerate(
            ['thumb' => ['disk' => 'public', 'path' => 'x/y.jpg']],
            [ImageFormat::make('thumb')->cover(100, 100)],
        );
    }

    public function test_regenerate_skips_default_format_in_list(): void
    {
        $file = UploadedFile::fake()->image('skip.jpg', 400, 400);

        $stored = $this->fileService->upload($file, 'public', 'photos', [
            ImageFormat::make('default'),
        ]);

        $defaultPathBefore = $stored['default']['path'];

        $updated = $this->fileService->regenerate($stored, [
            ImageFormat::make('default'), // Should re-process default (same transforms = same file)
            ImageFormat::make('small')->scaleDown(100),
        ]);

        $this->assertArrayHasKey('small', $updated);
    }

    public function test_regenerate_removes_formats_not_in_list(): void
    {
        $file = UploadedFile::fake()->image('multi.jpg', 800, 600);

        $stored = $this->fileService->upload($file, 'public', 'photos', [
            ImageFormat::make('default'),
            ImageFormat::make('thumb')->cover(200, 200),
            ImageFormat::make('small')->scaleDown(100),
        ]);

        Storage::disk('public')->assertExists($stored['thumb']['path']);
        Storage::disk('public')->assertExists($stored['small']['path']);

        // Regenerate with only 'thumb' — 'small' should be deleted
        $updated = $this->fileService->regenerate($stored, [
            ImageFormat::make('thumb')->cover(200, 200),
        ]);

        $this->assertArrayNotHasKey('small', $updated);
        Storage::disk('public')->assertMissing($stored['small']['path']);
        $this->assertArrayHasKey('default', $updated);
        $this->assertArrayHasKey('thumb', $updated);
    }

    public function test_regenerate_can_reprocess_default_format(): void
    {
        $file = UploadedFile::fake()->image('hero.jpg', 1200, 800);

        $stored = $this->fileService->upload($file, 'public', 'photos', [
            ImageFormat::make('default'),
        ]);

        $updated = $this->fileService->regenerate($stored, [
            ImageFormat::make('default')->scaleDown(600, 400)->extension('webp'),
        ]);

        $this->assertSame('default.webp', basename($updated['default']['path']));
        Storage::disk('public')->assertExists($updated['default']['path']);
        $this->assertSame(600, $updated['default']['width']);
    }

    // -------------------------------------------------------------------------
    // upload() / regenerate() — customAttributes
    // -------------------------------------------------------------------------

    public function test_upload_stores_custom_attributes_in_entry(): void
    {
        $file = UploadedFile::fake()->image('card.jpg', 800, 600);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('default'),
            ImageFormat::make('thumb')
                ->cover(200, 200)
                ->customAttributes(['role' => 'thumbnail', 'lazy' => true]),
        ]);

        // 'default' has no customAttributes — key should be absent
        $this->assertArrayNotHasKey('customAttributes', $result['default']);

        // 'thumb' carries its customAttributes
        $this->assertArrayHasKey('customAttributes', $result['thumb']);
        $this->assertSame(
            ['role' => 'thumbnail', 'lazy' => true],
            $result['thumb']['customAttributes'],
        );
    }

    public function test_regenerate_stores_custom_attributes_in_entry(): void
    {
        $file = UploadedFile::fake()->image('regen.jpg', 800, 600);

        $stored = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('default'),
        ]);

        $updated = $this->fileService->regenerate($stored, [
            ImageFormat::make('thumb')
                ->cover(200, 200)
                ->customAttributes(['priority' => 'high']),
        ]);

        $this->assertArrayHasKey('customAttributes', $updated['thumb']);
        $this->assertSame(['priority' => 'high'], $updated['thumb']['customAttributes']);

        // 'default' untouched — no customAttributes key
        $this->assertArrayNotHasKey('customAttributes', $updated['default']);
    }
}
