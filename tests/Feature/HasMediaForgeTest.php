<?php

namespace Webcimes\LaravelMediaforge\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Webcimes\LaravelMediaforge\ImageFormat;
use Webcimes\LaravelMediaforge\MediaForge;
use Webcimes\LaravelMediaforge\Tests\TestCase;
use Webcimes\LaravelMediaforge\Traits\HasMediaForge;

class HasMediaForgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        Schema::create('media_forge_items', function (Blueprint $table) {
            $table->id();
            $table->json('cover')->nullable();
            $table->json('gallery')->nullable();
            $table->json('content')->nullable();
        });
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    public function test_deleting_a_model_removes_single_and_multiple_uploads(): void
    {
        $item = MediaForgeItem::create([
            'cover' => $this->upload('cover.jpg'),
            'gallery' => [$this->upload('g1.jpg'), $this->upload('g2.jpg')],
            'content' => [
                'hero' => ['image' => $this->upload('hero.jpg')],
                'slides' => [['image' => $this->upload('s1.jpg')], ['image' => $this->upload('s2.jpg')]],
            ],
        ]);

        $paths = [
            $item->cover['default']['path'],
            $item->gallery[0]['default']['path'],
            $item->gallery[1]['default']['path'],
            $item->content['hero']['image']['default']['path'],
            $item->content['slides'][0]['image']['default']['path'],
            $item->content['slides'][1]['image']['default']['path'],
        ];
        foreach ($paths as $path) {
            Storage::disk('public')->assertExists($path);
        }

        $item->delete();

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(string $name): array
    {
        return app(MediaForge::class)->upload(
            UploadedFile::fake()->image($name, 300, 200),
            'public',
            'items',
            [ImageFormat::make('default')],
        );
    }
}

class MediaForgeItem extends Model
{
    use HasMediaForge;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['cover' => 'array', 'gallery' => 'array', 'content' => 'array'];

    protected array $mediaForgeColumns = ['cover', 'gallery', 'content.hero.image', 'content.slides.*.image'];
}
