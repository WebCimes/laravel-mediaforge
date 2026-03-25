<?php

namespace Webcimes\LaravelMediaforge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Webcimes\LaravelMediaforge\ImageFormat;

class ImageFormatTest extends TestCase
{
    public function test_make_returns_instance_with_correct_name(): void
    {
        $format = ImageFormat::make('thumb');

        $this->assertInstanceOf(ImageFormat::class, $format);
        $this->assertSame('thumb', $format->getName());
    }

    public function test_defaults_are_null_or_empty(): void
    {
        $format = ImageFormat::make('default');

        $this->assertNull($format->getDisk());
        $this->assertNull($format->getPath());
        $this->assertSame('', $format->getSuffix());
        $this->assertNull($format->getExtension());
        $this->assertNull($format->getFilename());
        $this->assertNull($format->getResizeType());
        $this->assertNull($format->getWidth());
        $this->assertNull($format->getHeight());
        $this->assertNull($format->getQuality());
        $this->assertNull($format->getText());
        $this->assertSame([], $format->getTextOptions());
        $this->assertNull($format->getWatermark());
        $this->assertSame([], $format->getWatermarkOptions());
        $this->assertSame([], $format->getCustomAttributes());
    }

    public function test_has_transforms_false_by_default(): void
    {
        $this->assertFalse(ImageFormat::make('default')->hasTransforms());
    }

    public function test_has_transforms_true_with_resize_type(): void
    {
        $this->assertTrue(ImageFormat::make('default')->scaleDown(1920)->hasTransforms());
    }

    public function test_has_transforms_true_with_quality(): void
    {
        $this->assertTrue(ImageFormat::make('default')->quality(80)->hasTransforms());
    }

    public function test_has_transforms_true_with_extension(): void
    {
        $this->assertTrue(ImageFormat::make('default')->extension('webp')->hasTransforms());
    }

    public function test_resize_sets_type_and_dimensions(): void
    {
        $format = ImageFormat::make('f')->resize(800, 600);

        $this->assertSame('resize', $format->getResizeType());
        $this->assertSame(800, $format->getWidth());
        $this->assertSame(600, $format->getHeight());
    }

    public function test_scale_allows_null_height(): void
    {
        $format = ImageFormat::make('f')->scale(1200);

        $this->assertSame('scale', $format->getResizeType());
        $this->assertSame(1200, $format->getWidth());
        $this->assertNull($format->getHeight());
    }

    public function test_scale_down_sets_type_and_dimensions(): void
    {
        $format = ImageFormat::make('f')->scaleDown(1920, 1080);

        $this->assertSame('scaleDown', $format->getResizeType());
        $this->assertSame(1920, $format->getWidth());
        $this->assertSame(1080, $format->getHeight());
    }

    public function test_cover_sets_type_and_dimensions(): void
    {
        $format = ImageFormat::make('f')->cover(400, 300);

        $this->assertSame('cover', $format->getResizeType());
        $this->assertSame(400, $format->getWidth());
        $this->assertSame(300, $format->getHeight());
    }

    public function test_to_config_array_omits_defaults(): void
    {
        $config = ImageFormat::make('default')->toConfigArray();

        $this->assertSame([], $config);
    }

    public function test_to_config_array_includes_set_values(): void
    {
        $config = ImageFormat::make('thumb')
            ->scaleDown(800, 600)
            ->quality(75)
            ->extension('webp')
            ->suffix('_2x')
            ->toConfigArray();

        $this->assertSame('scaleDown', $config['resizeType']);
        $this->assertSame(800, $config['width']);
        $this->assertSame(600, $config['height']);
        $this->assertSame(75, $config['quality']);
        $this->assertSame('webp', $config['extension']);
        $this->assertSame('_2x', $config['suffix']);
    }

    public function test_from_config_array_rebuilds_format(): void
    {
        $original = ImageFormat::make('thumb')
            ->cover(200, 200)
            ->extension('webp')
            ->quality(60);

        $rebuilt = ImageFormat::fromConfigArray('thumb', $original->toConfigArray());

        $this->assertSame('thumb', $rebuilt->getName());
        $this->assertSame('cover', $rebuilt->getResizeType());
        $this->assertSame(200, $rebuilt->getWidth());
        $this->assertSame(200, $rebuilt->getHeight());
        $this->assertSame('webp', $rebuilt->getExtension());
        $this->assertSame(60, $rebuilt->getQuality());
    }

    public function test_custom_attributes_stored_and_retrieved(): void
    {
        $format = ImageFormat::make('default')->customAttributes(['alt' => 'Hero image', 'caption' => 'Test']);

        $this->assertSame(['alt' => 'Hero image', 'caption' => 'Test'], $format->getCustomAttributes());
    }

    public function test_custom_attributes_serialised_in_config_array(): void
    {
        $config = ImageFormat::make('default')
            ->customAttributes(['focal' => 'top'])
            ->toConfigArray();

        $this->assertSame(['focal' => 'top'], $config['customAttributes']);
    }

    public function test_alt_stored_and_retrieved(): void
    {
        $format = ImageFormat::make('default')->alt('Hero image');

        $this->assertSame('Hero image', $format->getAlt());
    }

    public function test_alt_serialised_in_config_array(): void
    {
        $config = ImageFormat::make('default')->alt('My photo')->toConfigArray();

        $this->assertSame('My photo', $config['alt']);
    }

    public function test_alt_absent_from_config_array_when_not_set(): void
    {
        $config = ImageFormat::make('default')->toConfigArray();

        $this->assertArrayNotHasKey('alt', $config);
    }

    public function test_alt_rebuilt_from_config_array(): void
    {
        $rebuilt = ImageFormat::fromConfigArray('default', ['alt' => 'Rebuilt alt']);

        $this->assertSame('Rebuilt alt', $rebuilt->getAlt());
    }
}
