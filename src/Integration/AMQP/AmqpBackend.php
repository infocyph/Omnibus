<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\AMQP;

use Infocyph\Omnibus\Integration\Broker\BrokerBackend;

/**
 * Native AMQP clients adapt delivery tags, prefetch, acknowledgement, and
 * dead-letter topology through this boundary.
 */
interface AmqpBackend extends BrokerBackend {}
