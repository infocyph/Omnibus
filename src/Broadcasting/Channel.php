<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Broadcasting;

final readonly class Channel
{
    public function __construct(
        public string $name,
        public bool $private = false,
        public bool $presence = false,
    ) {
        if ($name === '' || strlen($name) > 200) {
            throw new \InvalidArgumentException('Channel names must contain between 1 and 200 bytes.');
        }
        if ($presence && !$private) {
            throw new \InvalidArgumentException('Presence channels must also be private.');
        }
    }
}
