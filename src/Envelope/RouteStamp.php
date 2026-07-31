<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Envelope;

use Infocyph\Omnibus\Transport\QueueName;

final readonly class RouteStamp implements Stamp
{
    public function __construct(
        public string $transport,
        public string $queue,
    ) {
        if (
            $transport === ''
            || strlen($transport) > 100
            || preg_match('/[\x00-\x1F\x7F]/D', $transport) === 1
        ) {
            throw new \InvalidArgumentException(
                'Transport names must contain between 1 and 100 bytes without control characters.',
            );
        }
        QueueName::assert($queue);
    }
}
