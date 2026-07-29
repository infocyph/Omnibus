<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

final readonly class ChainFailed
{
    public function __construct(
        public WorkflowState $state,
        public int $index,
    ) {}
}
