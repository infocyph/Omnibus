<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

final class WorkflowDispatchFailed extends \RuntimeException
{
    public function __construct(
        public readonly string $workflowId,
        \Throwable $previous,
    ) {
        parent::__construct(
            sprintf('Initial dispatch for workflow "%s" failed.', $workflowId),
            previous: $previous,
        );
    }
}
