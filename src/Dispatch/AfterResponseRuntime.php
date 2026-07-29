<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Dispatch;

interface AfterResponseRuntime
{
    /** @param callable():void $callback */
    public function defer(callable $callback): void;
}
