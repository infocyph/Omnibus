<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Fixtures;

use Infocyph\Omnibus\Event\ShouldQueue;

final class QueuedTestListener implements ShouldQueue
{
    public function __invoke(TestEvent $event): void
    {
        unset($event);
    }
}
