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

    // -------------------------------------------------------------------------
    // make() default name
    // -------------------------------------------------------------------------

    public function test_make_without_argument_uses_default_name(): void
    {
        $this->assertSame('default', ImageFormat::make()->getName());
    }

    // -------------------------------------------------------------------------
    // srcset
    // -------------------------------------------------------------------------

    public function test_srcset_sets_widths(): void
    {
        $format = ImageFormat::make('hero')->srcset([1920, 1080, 720]);

        $this->assertSame([1920, 1080, 720], $format->getSrcsetWidths());
    }

    public function test_is_srcset_returns_true_when_set(): void
    {
        $this->assertTrue(ImageFormat::make('hero')->srcset([1920])->isSrcset());
    }

    public function test_is_srcset_returns_false_by_default(): void
    {
        $this->assertFalse(ImageFormat::make('hero')->isSrcset());
    }

    public function test_srcset_filters_invalid_widths(): void
    {
        $format = ImageFormat::make('hero')->srcset([1920, 0, -100, 720]);

        $this->assertSame([1920, 720], $format->getSrcsetWidths());
    }

    public function test_srcset_without_args_sets_is_srcset_to_true(): void
    {
        $this->assertTrue(ImageFormat::make('hero')->srcset()->isSrcset());
    }

    public function test_expand_for_srcset_uses_scale_down(): void
    {
        $expanded = ImageFormat::make('hero')->srcset([1080])->expandForSrcset(1080);

        $this->assertSame('scaleDown', $expanded->getResizeType());
        $this->assertSame(1080, $expanded->getWidth());
        $this->assertNull($expanded->getHeight());
    }

    public function test_expand_for_srcset_respects_cover_resize_type(): void
    {
        $expanded = ImageFormat::make('thumb')->cover(1000, 1000)->srcset([400])->expandForSrcset(400);

        $this->assertSame('cover', $expanded->getResizeType());
        $this->assertSame(400, $expanded->getWidth());
        $this->assertSame(400, $expanded->getHeight());
    }

    public function test_expand_for_srcset_scales_height_proportionally(): void
    {
        // cover(1200, 400) → ratio 1/3 → at 600w height should be 200
        $expanded = ImageFormat::make('banner')->cover(1200, 400)->srcset([600])->expandForSrcset(600);

        $this->assertSame('cover', $expanded->getResizeType());
        $this->assertSame(600, $expanded->getWidth());
        $this->assertSame(200, $expanded->getHeight());
    }

    public function test_expand_for_srcset_respects_resize_type_without_height(): void
    {
        // scale(1200) — height is null, should stay null on variants
        $expanded = ImageFormat::make('hero')->scale(1200)->srcset([600])->expandForSrcset(600);

        $this->assertSame('scale', $expanded->getResizeType());
        $this->assertSame(600, $expanded->getWidth());
        $this->assertNull($expanded->getHeight());
    }

    public function test_expand_for_srcset_uses_correct_name(): void
    {
        $expanded = ImageFormat::make('hero')->srcset([720])->expandForSrcset(720);

        $this->assertSame('hero_720w', $expanded->getName());
    }

    public function test_expand_for_srcset_inherits_extension_and_quality(): void
    {
        $parent = ImageFormat::make('hero')
            ->srcset([720])
            ->extension('webp')
            ->quality(80);

        $expanded = $parent->expandForSrcset(720);

        $this->assertSame('webp', $expanded->getExtension());
        $this->assertSame(80, $expanded->getQuality());
    }

    public function test_expand_for_srcset_inherits_custom_filename_with_width_suffix(): void
    {
        $parent = ImageFormat::make('hero')->srcset([480])->filename('img');

        $expanded = $parent->expandForSrcset(480);

        $this->assertSame('img_480w', $expanded->getFilename());
    }

    public function test_expand_for_srcset_is_not_itself_srcset(): void
    {
        $expanded = ImageFormat::make('hero')->srcset([1080])->expandForSrcset(1080);

        $this->assertFalse($expanded->isSrcset());
    }

    // -------------------------------------------------------------------------
    // asBase()
    // -------------------------------------------------------------------------

    public function test_to_base_format_returns_same_name(): void
    {
        $base = ImageFormat::make('test')->cover(1000, 1000)->srcset([200, 400])->toBaseFormat();

        $this->assertSame('test', $base->getName());
    }

    public function test_to_base_format_is_not_srcset(): void
    {
        $base = ImageFormat::make('test')->srcset([200, 400])->toBaseFormat();

        $this->assertFalse($base->isSrcset());
    }

    public function test_to_base_format_preserves_resize_type_and_dimensions(): void
    {
        $base = ImageFormat::make('test')->cover(1000, 500)->srcset([200])->toBaseFormat();

        $this->assertSame('cover', $base->getResizeType());
        $this->assertSame(1000, $base->getWidth());
        $this->assertSame(500, $base->getHeight());
    }

    public function test_to_base_format_preserves_extension_and_quality(): void
    {
        $base = ImageFormat::make('test')->extension('webp')->quality(75)->srcset([200])->toBaseFormat();

        $this->assertSame('webp', $base->getExtension());
        $this->assertSame(75, $base->getQuality());
    }

    public function test_to_base_format_skip_larger_is_always_false(): void
    {
        // base format must never be filtered by the skipLarger guard in normalizeFormats()
        $base = ImageFormat::make('test')->cover(1000, 1000)->srcset([200], skipLarger: true)->toBaseFormat();

        $this->assertFalse($base->isSrcsetSkipLarger());
    }

    public function test_to_config_array_includes_srcset_widths(): void
    {
        $config = ImageFormat::make('hero')->srcset([1920, 1080])->toConfigArray();

        $this->assertSame([1920, 1080], $config['srcsetWidths']);
    }

    public function test_srcset_absent_from_config_array_when_not_set(): void
    {
        $config = ImageFormat::make('hero')->toConfigArray();

        $this->assertArrayNotHasKey('srcsetWidths', $config);
    }

    public function test_from_config_array_restores_srcset_widths(): void
    {
        $format = ImageFormat::fromConfigArray('hero', ['srcsetWidths' => [1920, 1080]]);

        $this->assertTrue($format->isSrcset());
        $this->assertSame([1920, 1080], $format->getSrcsetWidths());
    }

    public function test_srcset_skip_larger_defaults_to_true(): void
    {
        $format = ImageFormat::make('hero')->srcset([1920, 1080]);

        $this->assertTrue($format->isSrcsetSkipLarger());
    }

    public function test_srcset_skip_larger_can_be_disabled(): void
    {
        $format = ImageFormat::make('hero')->srcset([1920, 1080], skipLarger: false);

        $this->assertFalse($format->isSrcsetSkipLarger());
    }

    public function test_expand_for_srcset_propagates_skip_larger(): void
    {
        $expanded = ImageFormat::make('hero')->srcset([1920], skipLarger: false)->expandForSrcset(1920);

        $this->assertFalse($expanded->isSrcsetSkipLarger());
    }

    public function test_expand_for_srcset_skip_larger_true_by_default(): void
    {
        $expanded = ImageFormat::make('hero')->srcset([1920])->expandForSrcset(1920);

        $this->assertTrue($expanded->isSrcsetSkipLarger());
    }

    public function test_to_config_array_omits_skip_larger_when_default_true(): void
    {
        $config = ImageFormat::make('hero')->srcset([1920])->toConfigArray();

        $this->assertArrayNotHasKey('srcsetSkipLarger', $config);
    }

    public function test_to_config_array_includes_skip_larger_when_false(): void
    {
        $config = ImageFormat::make('hero')->srcset([1920], skipLarger: false)->toConfigArray();

        $this->assertFalse($config['srcsetSkipLarger']);
    }

    public function test_expanded_format_config_persists_skip_larger_false(): void
    {
        // Expanded formats have no srcsetWidths → srcsetSkipLarger is not serialized (irrelevant
        // toBaseFormat() explicitly forces skipLarger to false (base format is never filtered). Property default (false) is used.
        $expanded = ImageFormat::make('hero')->srcset([1920], skipLarger: false)->expandForSrcset(1920);
        $config = $expanded->toConfigArray();

        $this->assertArrayNotHasKey('srcsetSkipLarger', $config);
    }

    public function test_from_config_array_restores_skip_larger_on_expanded_format(): void
    {
        $format = ImageFormat::fromConfigArray('hero_1920w', ['resizeType' => 'scaleDown', 'width' => 1920, 'height' => null, 'srcsetSkipLarger' => false]);

        $this->assertFalse($format->isSrcsetSkipLarger());
    }

    public function test_from_config_array_skip_larger_defaults_to_true(): void
    {
        // Restored from a srcset parent format config where skipLarger was not saved (= API default true)
        $format = ImageFormat::fromConfigArray('hero', ['srcsetWidths' => [1920, 1080]]);

        $this->assertTrue($format->isSrcsetSkipLarger());
    }
}
