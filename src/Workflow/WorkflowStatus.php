<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

enum WorkflowStatus: string
{
    case Cancelled = 'cancelled';

    case Completed = 'completed';

    case Failed = 'failed';

    case Pending = 'pending';

    case Running = 'running';
}
