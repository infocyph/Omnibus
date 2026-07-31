<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Consumer\ExecutionScope;
use Infocyph\Omnibus\Envelope\BatchStamp;
use Infocyph\Omnibus\Envelope\ChainStamp;
use Infocyph\Omnibus\Envelope\Envelope;

final readonly class BatchCancellationScope implements ExecutionScope
{
    public function __construct(
        private ExecutionScope $inner,
        private WorkflowStore $workflows,
    ) {}

    public function run(Envelope $envelope, callable $handler): mixed
    {
        $stamp = $envelope->last(BatchStamp::class) ?? $envelope->last(ChainStamp::class);
        if ($stamp instanceof BatchStamp || $stamp instanceof ChainStamp) {
            $state = $this->workflows->find($stamp->workflowId);
            if ($state?->status === WorkflowStatus::Cancelled) {
                throw new WorkflowCancelled(sprintf(
                    'Batch "%s" was cancelled.',
                    $stamp->workflowId,
                ));
            }
        }

        return $this->inner->run($envelope, $handler);
    }
}
