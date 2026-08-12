<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Retry\NonRetryableFailure;

final class WorkflowInconsistentDelivery extends \RuntimeException implements NonRetryableFailure {}
