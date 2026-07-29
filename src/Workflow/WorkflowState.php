<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

final readonly class WorkflowState
{
    public function __construct(
        public string $id,
        public string $kind,
        public WorkflowStatus $status,
        public int $total,
        public int $succeeded,
        public int $failed,
        public int $cancelled,
    ) {
        if (
            $id === ''
            || !in_array($kind, ['batch', 'chain'], true)
            || $total < 1
            || min($succeeded, $failed, $cancelled) < 0
            || $succeeded + $failed + $cancelled > $total
        ) {
            throw new \InvalidArgumentException('Workflow state counters are invalid.');
        }
    }
}
