<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Handler;

use Infocyph\Omnibus\Retry\NonRetryableFailure;

final class HandlerNotFound extends \RuntimeException implements NonRetryableFailure {}
