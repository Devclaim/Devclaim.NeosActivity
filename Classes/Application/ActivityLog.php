<?php

declare(strict_types=1);

namespace Devclaim\NeosActivity\Application;

use Devclaim\NeosActivity\Domain\ActivityCursor;
use Devclaim\NeosActivity\Domain\ActivityEntry;
use Devclaim\NeosActivity\Domain\ActivityFilter;
use Devclaim\NeosActivity\Domain\ActivitySourceInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Utility\PositionalArraySorter;

/**
 * The log: the entries of all sources (Devclaim.NeosActivity.sources) in one list, newest first. Each source keeps its
 * own history; a page asks every source for its page and merges them (ActivityCursor gives the one order).
 */
#[Flow\Scope('singleton')]
final class ActivityLog
{
    /**
     * @var array<string, array{className?: string, position?: string|int}>
     */
    #[Flow\InjectConfiguration(path: 'sources', package: 'Devclaim.NeosActivity')]
    protected array $sourceConfiguration = [];

    #[Flow\Inject]
    protected ObjectManagerInterface $objectManager;

    /**
     * @var list<ActivitySourceInterface>|null
     */
    private ?array $sources = null;

    public function page(ActivityFilter $filter, ?ActivityCursor $after, int $limit): ActivityPage
    {
        $entries = [];
        foreach ($this->sources() as $source) {
            // one more than shown: whether there is a next page
            array_push($entries, ...$source->entries($filter, $after, $limit + 1));
        }
        usort($entries, static fn (ActivityEntry $a, ActivityEntry $b): int => self::compare($a, $b));
        $hasMore = count($entries) > $limit;
        $entries = array_slice($entries, 0, $limit);
        $last = $entries === [] ? null : $entries[count($entries) - 1];

        return new ActivityPage($entries, $hasMore && $last !== null ? ActivityCursor::afterEntry($last) : null);
    }

    /**
     * The order of the log: newest first, the same time by source, within a source by key descending
     */
    private static function compare(ActivityEntry $a, ActivityEntry $b): int
    {
        return [$b->time, $a->source, $b->key] <=> [$a->time, $b->source, $a->key];
    }

    /**
     * @return list<ActivitySourceInterface>
     */
    private function sources(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }
        $this->sources = [];
        foreach ((new PositionalArraySorter($this->sourceConfiguration))->toArray() as $key => $configuration) {
            $className = $configuration['className'] ?? null;
            if ($className === null) {
                continue;
            }
            $source = $this->objectManager->get($className);
            if (!$source instanceof ActivitySourceInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'The activity source "%s" (%s) does not implement %s',
                    $key,
                    $className,
                    ActivitySourceInterface::class
                ), 1793520001);
            }
            $this->sources[] = $source;
        }
        return $this->sources;
    }
}
