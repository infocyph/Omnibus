<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Retry\NonRetryableFailure;

final class WorkflowPostSettlementFailure extends \RuntimeException implements NonRetryableFailure
{
    public function __construct(
        public readonly string $workflowId,
        public readonly WorkflowState $state,
        public readonly string $operation,
        \Throwable $previous,
    ) {
        parent::__construct(
            sprintf('Workflow "%s" settlement completed, but %s failed.', $workflowId, $operation),
            previous: $previous,
        );
    }
}
