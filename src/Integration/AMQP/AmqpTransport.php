<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\AMQP;

use Infocyph\Omnibus\Integration\Broker\BrokerTransport;
use Infocyph\Omnibus\Serialization\EnvelopeSerializer;

final class AmqpTransport extends BrokerTransport
{
    public function __construct(AmqpBackend $backend, EnvelopeSerializer $serializer)
    {
        parent::__construct($backend, $serializer);
    }
}
