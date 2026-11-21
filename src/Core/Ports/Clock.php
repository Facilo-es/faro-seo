<?php

declare(strict_types=1);

namespace facilo\faro\Core\Ports;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
