<?php

declare(strict_types=1);

namespace Devclaim\NeosActivity\Domain;

/**
 * One thing that happened in the backend: who did what, when, to which item. A source creates them
 * (ActivitySourceInterface); the log only merges and shows them.
 */
final readonly class ActivityEntry
{
    /**
     * @param list<ActivityChange> $changes
     */
    public function __construct(
        /** the source that created the entry (its key in the settings) */
        public string $source,
        /** unique and sortable within the source: the position of the entry in it (see ActivityCursor) */
        public string $key,
        /** when it took effect (for content: when it went live) */
        public \DateTimeImmutable $time,
        /** when the user did it, if earlier (for content: the edit in a workspace before publishing) */
        public ?\DateTimeImmutable $initiatedAt,
        /** a Neos user id, or "system" */
        public ?string $userId,
        /** created, changed, moved, sorted, hidden, shown, removed, restored, … (Main.xlf "action.*") */
        public string $action,
        /** the kind of item, for content the node type name */
        public string $itemType,
        public string $itemId,
        public ?string $itemLabel,
        /** the page the item is on, for content the document node */
        public ?string $documentId,
        public ?string $documentLabel,
        /** whether the page still exists (and can be opened) */
        public bool $documentExists,
        public array $changes,
        /** the workspace it was done in before it was published, null if it was done in live directly */
        public ?string $workspaceName,
        /** entries of one action (a publish) share it */
        public ?string $group,
        /** the dimension of the item as JSON, for opening it */
        public ?string $dimensionSpacePoint,
    ) {
    }
}
