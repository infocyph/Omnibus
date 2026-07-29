<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\SQS;

use Infocyph\Omnibus\Integration\Broker\BrokerBackend;

/**
 * Native AWS clients adapt queue URLs, receipt handles, visibility, FIFO
 * deduplication, and approximate depth through this boundary.
 */
interface SqsBackend extends BrokerBackend {}
