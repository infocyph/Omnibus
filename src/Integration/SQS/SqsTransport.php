<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\SQS;

use Infocyph\Omnibus\Integration\Broker\BrokerTransport;
use Infocyph\Omnibus\Serialization\EnvelopeSerializer;

final class SqsTransport extends BrokerTransport
{
    public function __construct(SqsBackend $backend, EnvelopeSerializer $serializer)
    {
        parent::__construct($backend, $serializer);
    }
}
