<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Consumer\ExecutionScope;
use Infocyph\Omnibus\Envelope\BatchStamp;
use Infocyph\Omnibus\Envelope\ChainStamp;
use Infocyph\Omnibus\Envelope\Envelope;

final readonly class WorkflowExecutionScope implements ExecutionScope
{
    public function __construct(
        private ExecutionScope $inner,
        private WorkflowStore $workflows,
    ) {}

    public function run(Envelope $envelope, callable $handler): mixed
    {
        $identity = self::identity($envelope);
        if ($identity === null) {
            return $this->inner->run($envelope, $handler);
        }

        [$workflowId, $itemId, $index] = $identity;
        $status = $this->workflows->itemStatus($workflowId, $itemId, $index);

        if ($status === WorkflowItemStatus::Dispatched) {
            $result = $this->inner->run($envelope, $handler);
            $this->workflows->markHandled($workflowId, $itemId, $index);

            return $result;
        }

        if (in_array($status, [
            WorkflowItemStatus::Handled,
            WorkflowItemStatus::Succeeded,
            WorkflowItemStatus::Failed,
            WorkflowItemStatus::Cancelled,
        ], true)) {
            return null;
        }

        throw new WorkflowInconsistentDelivery(sprintf(
            'Workflow item "%s:%d" cannot execute while it is %s.',
            $workflowId,
            $index,
            $status->value,
        ));
    }

    /** @return array{string, string, int}|null */
    private static function identity(Envelope $envelope): ?array
    {
        $batch = $envelope->last(BatchStamp::class);
        if ($batch instanceof BatchStamp) {
            return [$batch->workflowId, $batch->itemId, $batch->index];
        }

        $chain = $envelope->last(ChainStamp::class);

        return $chain instanceof ChainStamp
            ? [$chain->workflowId, $chain->itemId, $chain->index]
            : null;
    }
}
