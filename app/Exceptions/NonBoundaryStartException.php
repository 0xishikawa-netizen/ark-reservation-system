<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

class NonBoundaryStartException extends DomainException
{
    // 顧客予約の開始時刻がスロット境界にない場合に使用する。
}
