<?php

declare(strict_types=1);

namespace Devclaim\NeosActivity\Domain;

/**
 * What the module shows. A source that cannot apply a filter returns nothing for it (the moderation source has no
 * documents), so the filtered list never shows something it was not asked for.
 */
final readonly class ActivityFilter
{
    /**
     * @param list<string>|null $itemTypes null: all
     * @param list<string>|null $actions null: all (ActivityEntry::$action)
     * @param string|null $search words in the name of the item or of its page
     */
    public function __construct(
        public ?string $userId = null,
        public ?array $itemTypes = null,
        public ?string $itemId = null,
        public ?string $documentId = null,
        public ?array $actions = null,
        public ?\DateTimeImmutable $since = null,
        public ?string $search = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->userId === null && $this->itemTypes === null && $this->itemId === null
            && $this->documentId === null && $this->actions === null && $this->since === null
            && $this->search === null;
    }

    /**
     * The same filter for any user: the counts per user next to the filter
     */
    public function withoutUser(): self
    {
        return new self(
            null,
            $this->itemTypes,
            $this->itemId,
            $this->documentId,
            $this->actions,
            $this->since,
            $this->search
        );
    }

    /**
     * The same filter for any kind of item: the counts per kind next to the filter
     */
    public function withoutItemTypes(): self
    {
        return new self(
            $this->userId,
            null,
            $this->itemId,
            $this->documentId,
            $this->actions,
            $this->since,
            $this->search
        );
    }
}
