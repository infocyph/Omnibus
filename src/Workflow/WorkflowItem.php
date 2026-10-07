<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Envelope\BatchStamp;
use Infocyph\Omnibus\Envelope\ChainStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Transport\QueueName;

final readonly class WorkflowItem
{
    public function __construct(
        public string $workflowId,
        public string $itemId,
        public int $index,
        public string $queue,
        public Envelope $envelope,
    ) {
        if (
            $workflowId === ''
            || strlen($workflowId) > 26
            || $itemId === ''
            || strlen($itemId) > 26
            || $index < 0
        ) {
            throw new \InvalidArgumentException('Workflow item fields are invalid.');
        }
        QueueName::assert($queue);

        $identity = self::identity($envelope);
        if (
            $identity !== null
            && (
                $identity['workflow_id'] !== $workflowId
                || $identity['item_id'] !== $itemId
                || $identity['index'] !== $index
            )
        ) {
            throw new WorkflowInconsistentDelivery(
                'Workflow item fields must match the envelope workflow identity.',
            );
        }
    }

    /** @return array{kind:'batch'|'chain',workflow_id:string,item_id:string,index:int}|null */
    public static function identity(Envelope $envelope): ?array
    {
        $batches = $envelope->all(BatchStamp::class);
        $chains = $envelope->all(ChainStamp::class);
        $count = count($batches) + count($chains);
        if ($count === 0) {
            return null;
        }
        if ($count !== 1) {
            throw new WorkflowInconsistentDelivery(
                'Workflow delivery must contain exactly one workflow identity stamp.',
            );
        }

        $stamp = $chains[0] ?? $batches[0];

        return [
            'kind' => $stamp instanceof ChainStamp ? 'chain' : 'batch',
            'workflow_id' => $stamp->workflowId,
            'item_id' => $stamp->itemId,
            'index' => $stamp->index,
        ];
    }
}
