<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

final class ExecutionTimedOut extends \RuntimeException
{
    public function __construct(public readonly \DateTimeImmutable $deadline)
    {
        parent::__construct(sprintf(
            'Message execution exceeded its cooperative deadline at %s.',
            $deadline->format(\DateTimeInterface::ATOM),
        ));
    }
}
