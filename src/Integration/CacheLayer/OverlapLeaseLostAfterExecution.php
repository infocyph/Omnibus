<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\CacheLayer;

use Infocyph\Omnibus\Retry\NonRetryableFailure;

final class OverlapLeaseLostAfterExecution extends LeaseLost implements NonRetryableFailure {}
