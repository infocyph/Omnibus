<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Broadcasting;

interface ChannelAuthorizer
{
    public function allows(object $principal, Channel $channel): bool;
}
