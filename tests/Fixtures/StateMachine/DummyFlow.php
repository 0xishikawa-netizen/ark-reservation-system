<?php

declare(strict_types=1);

namespace Tests\Fixtures\StateMachine;

use Illuminate\Database\Eloquent\Model;

class DummyFlow extends Model
{
    public $timestamps = false;

    protected $table = 'dummy_flows';

    /** @var list<string> */
    protected $fillable = ['status'];
}
