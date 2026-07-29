<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Telemetry;

interface TelemetrySink
{
    /** @param array<string, bool|float|int|string> $attributes */
    public function record(string $metric, float|int $value, array $attributes = []): void;
}
