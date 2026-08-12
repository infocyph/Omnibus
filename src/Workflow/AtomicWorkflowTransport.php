<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Transport\Reservation;

interface AtomicWorkflowTransport
{
    public function acknowledgeWorkflow(
        Reservation $reservation,
        WorkflowStore $store,
    ): WorkflowTransition;

    public function supportsWorkflowStore(WorkflowStore $store): bool;
}
