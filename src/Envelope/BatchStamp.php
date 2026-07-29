<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Envelope;

final readonly class BatchStamp implements Stamp
{
    public function __construct(
        public string $workflowId,
        public string $itemId,
        public int $index,
    ) {
        if ($workflowId === '' || $itemId === '' || $index < 0) {
            throw new \InvalidArgumentException('Batch stamp requires workflow/item IDs and non-negative index.');
        }
    }
}
