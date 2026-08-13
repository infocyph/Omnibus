<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

use Infocyph\Omnibus\Retry\NonRetryableFailure;

final class ExecutionTimedOutAfterExecution extends ExecutionTimedOut implements NonRetryableFailure {}
