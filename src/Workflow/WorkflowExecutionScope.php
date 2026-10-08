<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Consumer\ExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;

final readonly class WorkflowExecutionScope implements ExecutionScope
{
    public function __construct(
        private ExecutionScope $inner,
        private WorkflowStore $workflows,
    ) {}

    public function run(Envelope $envelope, callable $handler): mixed
    {
        $identity = WorkflowItem::identity($envelope);
        if ($identity === null) {
            return $this->inner->run($envelope, $handler);
        }

        $status = $this->workflows->itemStatus(
            $identity['workflow_id'],
            $identity['item_id'],
            $identity['index'],
        );
        if ($status === WorkflowItemStatus::Dispatched) {
            $result = $this->inner->run($envelope, $handler);
            $this->workflows->markHandled(
                $identity['workflow_id'],
                $identity['item_id'],
                $identity['index'],
            );

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
            $identity['workflow_id'],
            $identity['index'],
            $status->value,
        ));
    }
}
