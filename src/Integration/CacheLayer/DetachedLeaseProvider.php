<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\CacheLayer;

use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;

/**
 * Marker for token-based providers whose handles can be reconstructed by a
 * different worker. Process-bound file handles do not satisfy this contract.
 */
interface DetachedLeaseProvider extends LockProviderInterface {}
