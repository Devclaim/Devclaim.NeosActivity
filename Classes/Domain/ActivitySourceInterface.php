<?php

declare(strict_types=1);

namespace Devclaim\NeosActivity\Domain;

/**
 * A source of the log (Devclaim.NeosActivity.sources): content, moderation, … Each keeps its own history, the log
 * merges their pages when it is shown; nothing is copied.
 */
interface ActivitySourceInterface
{
    /**
     * The newest entries after the cursor (all from the newest if null) that pass the filter, at most $limit, in the
     * order of the log (ActivityCursor): newest first, same time by key descending.
     *
     * @return list<ActivityEntry>
     */
    public function entries(ActivityFilter $filter, ?ActivityCursor $after, int $limit): array;
}
