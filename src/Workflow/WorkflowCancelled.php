<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Retry\NonRetryableFailure;

final class WorkflowCancelled extends \RuntimeException implements NonRetryableFailure {}
