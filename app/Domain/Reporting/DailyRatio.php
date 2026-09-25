<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use JsonSerializable;

/** 分母0（未算出）と0%を区別した日次比率。 */
final readonly class DailyRatio implements JsonSerializable
{
    public ?float $value;

    public function __construct(
        public int $numerator,
        public int $denominator,
    ) {
        $this->value = $denominator === 0 ? null : $numerator / $denominator;
    }

    /** @return array{numerator: int, denominator: int, value: float|null} */
    public function jsonSerialize(): array
    {
        return [
            'numerator' => $this->numerator,
            'denominator' => $this->denominator,
            'value' => $this->value,
        ];
    }
}
