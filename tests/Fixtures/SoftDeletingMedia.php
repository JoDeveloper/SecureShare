<?php

namespace Jodeveloper\SecureShare\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class SoftDeletingMedia extends Media
{
    use SoftDeletes;
}
