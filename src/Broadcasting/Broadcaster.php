<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Broadcasting;

interface Broadcaster
{
    public function broadcast(Broadcast $broadcast): void;
}
