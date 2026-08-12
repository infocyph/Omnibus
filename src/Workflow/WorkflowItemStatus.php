<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

enum WorkflowItemStatus: string
{
    case Cancelled = 'cancelled';

    case Dispatched = 'dispatched';

    case Dispatching = 'dispatching';

    case Failed = 'failed';

    case Handled = 'handled';

    case Pending = 'pending';

    case Succeeded = 'succeeded';
}
