<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

final readonly class BatchFinalized
{
    public function __construct(public WorkflowState $state) {}
}
