<?php

namespace Ncpd\SecureShare\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as BaseUser;

class TestUser extends BaseUser
{
    protected $table = 'users';

    protected $guarded = [];
}
