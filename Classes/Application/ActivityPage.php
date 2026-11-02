<?php

declare(strict_types=1);

namespace Devclaim\NeosActivity\Application;

use Devclaim\NeosActivity\Domain\ActivityCursor;
use Devclaim\NeosActivity\Domain\ActivityEntry;

/**
 * One page of the log
 */
final readonly class ActivityPage
{
    /**
     * @param list<ActivityEntry> $entries
     */
    public function __construct(
        public array $entries,
        /** where the next page starts, null on the last page */
        public ?ActivityCursor $next,
    ) {
    }
}
