<?php

namespace Webcimes\LaravelMediaforge\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimal Eloquent model used by feature tests that need a real DB row.
 * The table is created/dropped inline within each test that uses it.
 */
class TestMediaModel extends Model
{
    protected $table = 'test_media_models';

    protected $fillable = ['media'];

    protected $casts = ['media' => 'array'];

    public $timestamps = false;
}
