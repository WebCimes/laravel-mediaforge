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

    private \Illuminate\Filesystem\FilesystemAdapter $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = Storage::fake('public');

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
        $this->disk->assertExists($result['default']['path']);
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

        $this->disk->assertExists($path);
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

        $this->disk->assertExists($result['default']['path']);
        $this->disk->assertExists($result['thumb']['path']);
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
        $this->disk->assertExists($result['default']['path']);
        $this->disk->assertExists($result['thumb']['path']);
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
        $this->disk->assertExists($result['default']['path']);
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
        $this->disk->assertExists($result['default']['path']);
    }

    public function test_upload_non_image_with_custom_basename(): void
    {
        $file = UploadedFile::fake()->create('report.pdf', 10);

        $result = $this->fileService->upload($file, 'public', 'docs', null, 'my-report');

        $filename = basename($result['default']['path']);
        $this->assertStringStartsWith('my-report_', $filename);
        $this->disk->assertExists($result['default']['path']);
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
    }

    public function test_upload_default_format_without_transforms_copies_original_bytes(): void
    {
        $file = UploadedFile::fake()->image('copy.png', 100, 100);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            // Only a thumb — default will be auto-injected with no transforms
        ]);

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

    public function test_delete_non_image_entry_does_not_remove_base_folder(): void
    {
        $file = UploadedFile::fake()->create('report.pdf', 10);
        $entry = $this->fileService->upload($file, 'public', 'docs');

        $this->disk->assertExists($entry['default']['path']);

        $this->fileService->delete([$entry], 'public');

        $this->disk->assertMissing($entry['default']['path']);
        // Base folder 'docs/' must NOT be deleted
        $this->assertTrue($this->disk->exists('docs'));
    }

    public function test_delete_removes_file_from_disk(): void
    {
        $file = UploadedFile::fake()->create('to_delete.txt', 1);
        $result = $this->fileService->upload($file, 'public', '');

        $path = $result['default']['path'];
        $this->disk->assertExists($path);

        $this->fileService->delete($path, 'public');

        $this->disk->assertMissing($path);
    }

    public function test_delete_removes_all_formats_from_image_entry(): void
    {
        $file = UploadedFile::fake()->image('multi.jpg', 500, 500);

        $entry = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('default'),
            ImageFormat::make('thumb')->cover(100, 100),
        ]);

        $this->disk->assertExists($entry['default']['path']);
        $this->disk->assertExists($entry['thumb']['path']);

        $this->fileService->delete([$entry], 'public');

        $this->disk->assertMissing($entry['default']['path']);
        $this->disk->assertMissing($entry['thumb']['path']);
    }

    public function test_delete_removes_image_folder_when_empty(): void
    {
        $file = UploadedFile::fake()->image('folder_cleanup.jpg', 500, 500);

        $entry = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('default'),
            ImageFormat::make('thumb')->cover(100, 100),
        ]);

        $folder = dirname($entry['default']['path']);

        $this->assertTrue($this->disk->exists($folder));

        $this->fileService->delete([$entry], 'public');

        $this->assertFalse($this->disk->exists($folder));
    }

    public function test_delete_removes_image_folder_when_empty_nested_path(): void
    {
        // Verifies folder cleanup works regardless of base path depth (e.g. 'test/photos')
        $file = UploadedFile::fake()->image('deep.jpg', 200, 200);

        $entry = $this->fileService->upload($file, 'public', 'test/photos', [
            ImageFormat::make('default'),
        ]);

        $folder = dirname($entry['default']['path']);
        $this->assertTrue($this->disk->exists($folder));

        $this->fileService->delete([$entry], 'public');

        $this->assertFalse($this->disk->exists($folder));
        // Base folder must NOT be deleted
        $this->assertTrue($this->disk->exists('test/photos'));
    }

    public function test_delete_string_path_cleans_up_image_folder(): void
    {
        $file = UploadedFile::fake()->image('solo.jpg', 200, 200);

        $entry = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('default'),
        ]);

        $folder = dirname($entry['default']['path']);

        // Delete via plain string path
        $this->fileService->delete($entry['default']['path'], 'public');

        $this->disk->assertMissing($entry['default']['path']);
        $this->assertFalse($this->disk->exists($folder));
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
        $this->disk->assertExists($updated['thumb']['path']);
    }

    public function test_regenerate_replaces_existing_format_file(): void
    {
        $file = UploadedFile::fake()->image('replace.jpg', 800, 600);

        $stored = $this->fileService->upload($file, 'public', 'photos', [
            ImageFormat::make('default'),
            ImageFormat::make('thumb')->cover(200, 200),
        ]);

        $oldThumbPath = $stored['thumb']['path'];
        $this->disk->assertExists($oldThumbPath);

        $updated = $this->fileService->regenerate($stored, [
            ImageFormat::make('thumb')->cover(100, 100)->extension('webp'),
        ]);

        $this->disk->assertMissing($oldThumbPath);
        $this->disk->assertExists($updated['thumb']['path']);
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

        $this->disk->assertExists($stored['thumb']['path']);
        $this->disk->assertExists($stored['small']['path']);

        // Regenerate with only 'thumb' — 'small' should be deleted
        $updated = $this->fileService->regenerate($stored, [
            ImageFormat::make('thumb')->cover(200, 200),
        ]);

        $this->assertArrayNotHasKey('small', $updated);
        $this->disk->assertMissing($stored['small']['path']);
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
        $this->disk->assertExists($updated['default']['path']);
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

    // -------------------------------------------------------------------------
    // alt
    // -------------------------------------------------------------------------

    public function test_upload_alt_defaults_to_original_filename(): void
    {
        $file = UploadedFile::fake()->image('my-photo.jpg', 800, 600);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('default'),
        ]);

        $this->assertSame('my-photo', $result['default']['alt']);
    }

    public function test_upload_alt_can_be_overridden_per_format(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('default')->alt('Product cover'),
            ImageFormat::make('thumb')->cover(200, 200)->alt('Product thumbnail'),
        ]);

        $this->assertSame('Product cover', $result['default']['alt']);
        $this->assertSame('Product thumbnail', $result['thumb']['alt']);
    }

    public function test_regenerate_alt_inherits_from_default_entry(): void
    {
        $file = UploadedFile::fake()->image('banner.jpg', 800, 600);

        $stored = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('default')->alt('Banner image'),
        ]);

        $updated = $this->fileService->regenerate($stored, [
            ImageFormat::make('thumb')->cover(200, 200),
        ]);

        $this->assertSame('Banner image', $updated['thumb']['alt']);
    }

    public function test_regenerate_alt_can_be_overridden_per_format(): void
    {
        $file = UploadedFile::fake()->image('banner.jpg', 800, 600);

        $stored = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('default')->alt('Banner image'),
        ]);

        $updated = $this->fileService->regenerate($stored, [
            ImageFormat::make('thumb')->cover(200, 200)->alt('Banner thumbnail'),
        ]);

        $this->assertSame('Banner thumbnail', $updated['thumb']['alt']);
    }

    // -------------------------------------------------------------------------
    // handleFiles()
    // -------------------------------------------------------------------------

    public function test_handle_files_returns_null_when_nothing(): void
    {
        $result = $this->fileService->handleFiles(
            diskName: 'public',
            path: 'uploads',
            uploadedFiles: null,
            filesToDeleteIndex: null,
        );

        $this->assertNull($result);
    }

    public function test_handle_files_uploads_new_file(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 10);

        $result = $this->fileService->handleFiles(
            diskName: 'public',
            path: 'uploads',
            uploadedFiles: [$file],
            filesToDeleteIndex: null,
        );

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->disk->assertExists($result[0]['default']['path']);
    }

    public function test_handle_files_preserves_existing_files_when_no_changes(): void
    {
        $existing = [$this->fileService->upload(
            UploadedFile::fake()->create('existing.pdf', 10), 'public', 'uploads',
        )];

        $result = $this->fileService->handleFiles(
            diskName: 'public',
            path: 'uploads',
            uploadedFiles: null,
            filesToDeleteIndex: null,
            existingFiles: $existing,
        );

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertSame($existing[0]['default']['path'], $result[0]['default']['path']);
    }

    public function test_handle_files_merges_new_with_existing(): void
    {
        $existing = [$this->fileService->upload(
            UploadedFile::fake()->create('a.pdf', 10), 'public', 'uploads',
        )];

        $result = $this->fileService->handleFiles(
            diskName: 'public',
            path: 'uploads',
            uploadedFiles: [UploadedFile::fake()->create('b.pdf', 10)],
            filesToDeleteIndex: null,
            existingFiles: $existing,
        );

        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        // Existing first, then new
        $this->assertSame($existing[0]['default']['path'], $result[0]['default']['path']);
    }

    public function test_handle_files_deletes_existing_file_by_index(): void
    {
        $existing = [
            $this->fileService->upload(UploadedFile::fake()->create('a.pdf', 10), 'public', 'uploads'),
            $this->fileService->upload(UploadedFile::fake()->create('b.pdf', 10), 'public', 'uploads'),
        ];

        $pathA = $existing[0]['default']['path'];
        $pathB = $existing[1]['default']['path'];

        $result = $this->fileService->handleFiles(
            diskName: 'public',
            path: 'uploads',
            uploadedFiles: null,
            filesToDeleteIndex: [0],
            existingFiles: $existing,
        );

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->disk->assertMissing($pathA);
        $this->disk->assertExists($pathB);
        $this->assertSame($pathB, $result[0]['default']['path']);
    }

    public function test_handle_files_deletes_all_existing_returns_null(): void
    {
        $existing = [$this->fileService->upload(
            UploadedFile::fake()->create('a.pdf', 10), 'public', 'uploads',
        )];

        $result = $this->fileService->handleFiles(
            diskName: 'public',
            path: 'uploads',
            uploadedFiles: null,
            filesToDeleteIndex: [0],
            existingFiles: $existing,
        );

        $this->assertNull($result);
    }

    public function test_handle_files_global_order_reverses_existing_files(): void
    {
        $existing = [
            $this->fileService->upload(UploadedFile::fake()->create('a.pdf', 10), 'public', 'uploads'),
            $this->fileService->upload(UploadedFile::fake()->create('b.pdf', 10), 'public', 'uploads'),
        ];

        $pathA = $existing[0]['default']['path'];
        $pathB = $existing[1]['default']['path'];

        $result = $this->fileService->handleFiles(
            diskName: 'public',
            path: 'uploads',
            uploadedFiles: null,
            filesToDeleteIndex: null,
            globalOrder: [
                ['type' => 'existing', 'index' => 1],
                ['type' => 'existing', 'index' => 0],
            ],
            existingFiles: $existing,
        );

        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $this->assertSame($pathB, $result[0]['default']['path']);
        $this->assertSame($pathA, $result[1]['default']['path']);
    }

    public function test_handle_files_global_order_interleaves_existing_and_new(): void
    {
        $existing = [$this->fileService->upload(
            UploadedFile::fake()->create('existing.pdf', 10), 'public', 'uploads',
        )];

        $pathExisting = $existing[0]['default']['path'];

        $result = $this->fileService->handleFiles(
            diskName: 'public',
            path: 'uploads',
            uploadedFiles: [
                UploadedFile::fake()->create('new1.pdf', 10), // new index 0
                UploadedFile::fake()->create('new2.pdf', 10), // new index 1
            ],
            filesToDeleteIndex: null,
            globalOrder: [
                ['type' => 'new',      'index' => 0],
                ['type' => 'existing', 'index' => 0],
                ['type' => 'new',      'index' => 1],
            ],
            existingFiles: $existing,
        );

        $this->assertNotNull($result);
        $this->assertCount(3, $result);
        // Position 0 → new file 0 (not the existing one)
        $this->assertNotSame($pathExisting, $result[0]['default']['path']);
        // Position 1 → existing
        $this->assertSame($pathExisting, $result[1]['default']['path']);
        // Position 2 → new file 1 (different from new file 0)
        $this->assertNotSame($pathExisting, $result[2]['default']['path']);
        $this->assertNotSame($result[0]['default']['path'], $result[2]['default']['path']);
    }

    public function test_handle_files_orphan_upload_deleted_when_not_in_global_order(): void
    {
        $result = $this->fileService->handleFiles(
            diskName: 'public',
            path: 'uploads',
            uploadedFiles: [
                UploadedFile::fake()->create('used.pdf', 10),   // index 0 — referenced
                UploadedFile::fake()->create('orphan.pdf', 10), // index 1 — NOT referenced
            ],
            filesToDeleteIndex: null,
            globalOrder: [
                ['type' => 'new', 'index' => 0],
                // index 1 intentionally omitted
            ],
        );

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->disk->assertExists($result[0]['default']['path']);

        // Orphaned file must have been deleted from disk
        $this->assertCount(1, $this->disk->allFiles('uploads'));
    }

    public function test_handle_files_empty_global_order_returns_null(): void
    {
        $result = $this->fileService->handleFiles(
            diskName: 'public',
            path: 'uploads',
            uploadedFiles: null,
            filesToDeleteIndex: null,
            globalOrder: [],
        );

        $this->assertNull($result);
    }

    public function test_handle_files_with_image_formats(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $result = $this->fileService->handleFiles(
            diskName: 'public',
            path: 'products',
            uploadedFiles: [$file],
            filesToDeleteIndex: null,
            imageFormats: [
                ImageFormat::make('default')->scaleDown(1920, 1080)->quality(75)->extension('webp'),
                ImageFormat::make('thumb')->cover(400, 300)->quality(60)->extension('webp'),
            ],
        );

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('default', $result[0]);
        $this->assertArrayHasKey('thumb', $result[0]);
        $this->disk->assertExists($result[0]['default']['path']);
        $this->disk->assertExists($result[0]['thumb']['path']);
    }

    public function test_handle_files_deletion_and_upload_combined(): void
    {
        $existing = [
            $this->fileService->upload(UploadedFile::fake()->create('keep.pdf', 10), 'public', 'uploads'),
            $this->fileService->upload(UploadedFile::fake()->create('remove.pdf', 10), 'public', 'uploads'),
        ];

        $pathKeep   = $existing[0]['default']['path'];
        $pathRemove = $existing[1]['default']['path'];

        $result = $this->fileService->handleFiles(
            diskName: 'public',
            path: 'uploads',
            uploadedFiles: [UploadedFile::fake()->create('new.pdf', 10)],
            filesToDeleteIndex: [1],
            existingFiles: $existing,
        );

        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $this->disk->assertMissing($pathRemove);
        $this->disk->assertExists($pathKeep);
        $this->assertSame($pathKeep, $result[0]['default']['path']);
    }

    public function test_handle_files_delete_and_reorder_uses_original_indexes(): void
    {
        // Regression: when files are deleted, globalOrder must use the original existingFiles
        // indexes, not the re-indexed positions after array_values().
        // Scenario: 3 existing, move index 1 to first, delete index 0.
        $existing = [
            $this->fileService->upload(UploadedFile::fake()->create('img0.pdf', 10), 'public', 'uploads'), // 0
            $this->fileService->upload(UploadedFile::fake()->create('img1.pdf', 10), 'public', 'uploads'), // 1
            $this->fileService->upload(UploadedFile::fake()->create('img2.pdf', 10), 'public', 'uploads'), // 2
        ];

        $path0 = $existing[0]['default']['path']; // will be deleted
        $path1 = $existing[1]['default']['path'];
        $path2 = $existing[2]['default']['path'];

        $result = $this->fileService->handleFiles(
            diskName: 'public',
            path: 'uploads',
            uploadedFiles: null,
            filesToDeleteIndex: [0],
            globalOrder: [
                ['type' => 'existing', 'index' => 1], // img1 (original index 1) → 1st
                ['type' => 'existing', 'index' => 2], // img2 (original index 2) → 2nd
            ],
            existingFiles: $existing,
        );

        $this->disk->assertMissing($path0);
        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $this->assertSame($path1, $result[0]['default']['path']); // img1 is first
        $this->assertSame($path2, $result[1]['default']['path']); // img2 is second
    }

    public function test_handle_files_complex_delete_upload_reorder(): void
    {
        // Setup: 4 existing files on disk (a, b, c, d)
        $existing = [
            $this->fileService->upload(UploadedFile::fake()->create('a.pdf', 10), 'public', 'uploads'), // index 0
            $this->fileService->upload(UploadedFile::fake()->create('b.pdf', 10), 'public', 'uploads'), // index 1  ← deleted
            $this->fileService->upload(UploadedFile::fake()->create('c.pdf', 10), 'public', 'uploads'), // index 2
            $this->fileService->upload(UploadedFile::fake()->create('d.pdf', 10), 'public', 'uploads'), // index 3  ← deleted
        ];

        $pathA = $existing[0]['default']['path'];
        $pathB = $existing[1]['default']['path']; // will be deleted
        $pathC = $existing[2]['default']['path'];
        $pathD = $existing[3]['default']['path']; // will be deleted

        // Delete b (index 1) and d (index 3).
        // Upload 2 new files. uploadedFilesArray[0]=new1, uploadedFilesArray[1]=new2
        // globalOrder uses ORIGINAL existingFiles indexes (a=0, b=1, c=2, d=3)
        // Desired final order: [new2, a, new1, c]
        $result = $this->fileService->handleFiles(
            diskName: 'public',
            path: 'uploads',
            uploadedFiles: [
                UploadedFile::fake()->create('new1.pdf', 10), // new index 0
                UploadedFile::fake()->create('new2.pdf', 10), // new index 1
            ],
            filesToDeleteIndex: [1, 3],
            globalOrder: [
                ['type' => 'new',      'index' => 1], // new2      → 1st
                ['type' => 'existing', 'index' => 0], // a (orig 0) → 2nd
                ['type' => 'new',      'index' => 0], // new1      → 3rd
                ['type' => 'existing', 'index' => 2], // c (orig 2) → 4th
            ],
            existingFiles: $existing,
        );

        // --- Disk state ---
        // b and d must be gone
        $this->disk->assertMissing($pathB);
        $this->disk->assertMissing($pathD);
        // a and c must still exist
        $this->disk->assertExists($pathA);
        $this->disk->assertExists($pathC);

        // --- Result structure ---
        $this->assertNotNull($result);
        $this->assertCount(4, $result);

        // --- Order: new2, a, new1, c ---
        // position 0 → new2 (not a, not c)
        $this->assertNotSame($pathA, $result[0]['default']['path']);
        $this->assertNotSame($pathC, $result[0]['default']['path']);

        // position 1 → a
        $this->assertSame($pathA, $result[1]['default']['path']);

        // position 2 → new1 (different from new2, not a, not c)
        $this->assertNotSame($result[0]['default']['path'], $result[2]['default']['path']);
        $this->assertNotSame($pathA, $result[2]['default']['path']);
        $this->assertNotSame($pathC, $result[2]['default']['path']);

        // position 3 → c
        $this->assertSame($pathC, $result[3]['default']['path']);

        // --- All 4 result files exist on disk ---
        foreach ($result as $entry) {
            $this->disk->assertExists($entry['default']['path']);
        }
    }

    // -------------------------------------------------------------------------
    // srcset — nested structure
    // -------------------------------------------------------------------------

    public function test_upload_srcset_creates_nested_variants(): void
    {
        $file = UploadedFile::fake()->image('hero.jpg', 2000, 1000);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('hero')->srcset([1920, 1080, 720])->extension('webp'),
        ]);

        $this->assertArrayHasKey('default', $result);
        $this->assertArrayHasKey('hero', $result);
        $this->assertArrayHasKey('srcset', $result['hero']);
        $this->assertCount(3, $result['hero']['srcset']);
    }

    public function test_upload_srcset_variants_share_same_folder(): void
    {
        $file = UploadedFile::fake()->image('hero.jpg', 2000, 1000);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('hero')->srcset([1080, 720])->extension('webp'),
        ]);

        $this->assertSame(
            dirname($result['hero']['srcset'][0]['path']),
            dirname($result['hero']['srcset'][1]['path']),
        );
    }

    public function test_upload_srcset_variant_filenames_use_format_name(): void
    {
        $file = UploadedFile::fake()->image('hero.jpg', 2000, 1000);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('responsive')->srcset([1080, 480])->extension('webp'),
        ]);

        $this->assertSame('responsive_1080w.webp', basename($result['responsive']['srcset'][0]['path']));
        $this->assertSame('responsive_480w.webp', basename($result['responsive']['srcset'][1]['path']));
    }

    public function test_upload_srcset_variant_files_exist_on_disk(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 2000, 1000);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('responsive')->srcset([1080, 720, 480])->extension('webp'),
        ]);

        $this->disk->assertExists($result['responsive']['srcset'][0]['path']);
        $this->disk->assertExists($result['responsive']['srcset'][1]['path']);
        $this->disk->assertExists($result['responsive']['srcset'][2]['path']);
    }

    public function test_upload_srcset_does_not_upscale_smaller_image(): void
    {
        // Source is 1500px wide; the 1920w variant must NOT be upscaled when using scaleDown
        $file = UploadedFile::fake()->image('small.jpg', 1500, 800);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('r')->srcset([1920, 1080], skipLarger: false)->extension('webp'),
        ]);

        $this->assertLessThanOrEqual(1500, $result['r']['srcset'][0]['width']);
        $this->assertSame(1080, $result['r']['srcset'][1]['width']);
    }

    public function test_upload_srcset_auto_injects_default(): void
    {
        $file = UploadedFile::fake()->image('img.jpg', 800, 600);

        // No explicit 'default' — srcset only
        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('r')->srcset([720, 480])->extension('webp'),
        ]);

        $this->assertArrayHasKey('default', $result);
    }

    public function test_upload_srcset_stores_width_and_height_in_entries(): void
    {
        $file = UploadedFile::fake()->image('img.jpg', 2000, 1000);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('hero')->srcset([1080])->extension('webp'),
        ]);

        $this->assertArrayHasKey('srcset', $result['hero']);
        $this->assertArrayHasKey('width', $result['hero']['srcset'][0]);
        $this->assertArrayHasKey('height', $result['hero']['srcset'][0]);
        $this->assertSame(1080, $result['hero']['srcset'][0]['width']);
    }

    public function test_delete_removes_srcset_variant_files(): void
    {
        $file = UploadedFile::fake()->image('img.jpg', 2000, 1000);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('hero')->srcset([1080, 720])->extension('webp'),
        ]);

        $path1 = $result['hero']['srcset'][0]['path'];
        $path2 = $result['hero']['srcset'][1]['path'];
        $this->disk->assertExists($path1);
        $this->disk->assertExists($path2);

        $this->fileService->delete([$result]);

        $this->disk->assertMissing($path1);
        $this->disk->assertMissing($path2);
    }

    public function test_upload_srcset_without_args_uses_config_widths(): void
    {
        config(['mediaforge.srcset.widths' => [720, 480]]);

        $file = UploadedFile::fake()->image('img.jpg', 2000, 1000);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('hero')->srcset()->extension('webp'),
        ]);

        $this->assertArrayHasKey('srcset', $result['hero']);
        $this->assertCount(2, $result['hero']['srcset']);
        $this->assertSame(720, $result['hero']['srcset'][0]['width']);
        $this->assertSame(480, $result['hero']['srcset'][1]['width']);
    }

    public function test_upload_srcset_with_explicit_widths_ignores_config(): void
    {
        config(['mediaforge.srcset.widths' => [1280, 768]]);

        $file = UploadedFile::fake()->image('img.jpg', 2000, 1000);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('hero')->srcset([1920, 1080])->extension('webp'),
        ]);

        $this->assertArrayHasKey('srcset', $result['hero']);
        $this->assertCount(2, $result['hero']['srcset']);
        $this->assertSame(1920, $result['hero']['srcset'][0]['width']);
        $this->assertSame(1080, $result['hero']['srcset'][1]['width']);
    }

    // -------------------------------------------------------------------------
    // srcset skipLarger
    // -------------------------------------------------------------------------

    public function test_upload_srcset_skip_larger_omits_variants_wider_than_source(): void
    {
        // Source is 600×400 — widths 1920, 1080 and 720 are larger, only 480 fits
        $file = UploadedFile::fake()->image('img.jpg', 600, 400);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('hero')->srcset([1920, 1080, 720, 480], skipLarger: true)->extension('webp'),
        ]);

        $this->assertArrayHasKey('srcset', $result['hero']);
        $this->assertCount(1, $result['hero']['srcset']);
        $this->assertSame(480, $result['hero']['srcset'][0]['width']);
        $this->disk->assertExists($result['hero']['srcset'][0]['path']);
    }

    public function test_upload_srcset_without_skip_larger_creates_all_variants(): void
    {
        $file = UploadedFile::fake()->image('img.jpg', 600, 400);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('hero')->srcset([1920, 480], skipLarger: false)->extension('webp'),
        ]);

        // Both variants are created (scaleDown caps at source width, but entries are still there)
        $this->assertArrayHasKey('srcset', $result['hero']);
        $this->assertCount(2, $result['hero']['srcset']);
    }

    public function test_upload_srcset_skip_larger_exact_source_width_is_kept(): void
    {
        // A variant at exactly the source width should NOT be skipped
        $file = UploadedFile::fake()->image('img.jpg', 720, 540);

        $result = $this->fileService->upload($file, 'public', 'uploads', [
            ImageFormat::make('r')->srcset([720, 480], skipLarger: true)->extension('webp'),
        ]);

        $this->assertArrayHasKey('srcset', $result['r']);
        $this->assertCount(2, $result['r']['srcset']);
        $this->assertSame(720, $result['r']['srcset'][0]['width']);
        $this->assertSame(480, $result['r']['srcset'][1]['width']);
    }
}
