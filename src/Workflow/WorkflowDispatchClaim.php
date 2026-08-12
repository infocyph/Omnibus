<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

final readonly class WorkflowDispatchClaim
{
    public function __construct(
        public WorkflowItem $item,
        public string $token,
        public int $expiresAtMicroseconds,
    ) {
        if (
            $token === ''
            || strlen($token) > 191
            || preg_match('/[\x00-\x1F\x7F]/D', $token) === 1
            || $expiresAtMicroseconds < 0
        ) {
            throw new \InvalidArgumentException('Workflow dispatch claim fields are invalid.');
        }
    }
}
