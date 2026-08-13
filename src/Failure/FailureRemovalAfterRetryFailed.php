<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Failure;

use Infocyph\Omnibus\Envelope\Envelope;

final class FailureRemovalAfterRetryFailed extends \RuntimeException
{
    public function __construct(
        public readonly string $failureId,
        public readonly Envelope $sentEnvelope,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'Failed message "%s" was sent, but its failure record could not be finalized.',
            $failureId,
        ), previous: $previous);
    }
}
