<?php

declare(strict_types=1);

namespace Devclaim\NeosActivity\ContentRepository;

use Devclaim\NeosActivity\Domain\ActivityChange;
use Devclaim\NeosActivity\Domain\ActivityCursor;
use Devclaim\NeosActivity\Domain\ActivityEntry;
use Devclaim\NeosActivity\Domain\ActivityFilter;
use Devclaim\NeosActivity\Domain\ActivitySourceInterface;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;

/**
 * The changes of the content, from the activity projection (ActivityProjection)
 */
#[Flow\Scope('singleton')]
final class ContentActivitySource implements ActivitySourceInterface
{
    public const SOURCE = 'content';

    /** the key: the sequence number, padded so that keys sort like numbers (ActivityCursor) */
    private const KEY_LENGTH = 20;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\InjectConfiguration(path: 'contentRepository', package: 'Devclaim.NeosActivity')]
    protected string $contentRepository;

    public function entries(ActivityFilter $filter, ?ActivityCursor $after, int $limit): array
    {
        $contentRepository = $this->contentRepositoryRegistry->get($this->contentRepositoryId());
        $nodeTypeManager = $contentRepository->getNodeTypeManager();
        $rows = $contentRepository->projectionState(ActivityFinder::class)
            ->findEntries($filter, $after, self::SOURCE, $limit);

        return array_map(fn (array $row): ActivityEntry => $this->entryOf($row, $nodeTypeManager), $rows);
    }

    /**
     * One entry by its key (ActivityEntry::$key), null if it is not (or no longer) in the log
     */
    public function entry(string $key): ?ActivityEntry
    {
        if (preg_match('/^\d{1,' . self::KEY_LENGTH . '}$/', $key) !== 1) {
            return null;
        }
        $contentRepository = $this->contentRepositoryRegistry->get($this->contentRepositoryId());
        $row = $contentRepository->projectionState(ActivityFinder::class)->findEntry((int)$key);

        return $row === null ? null : $this->entryOf($row, $contentRepository->getNodeTypeManager());
    }

    /**
     * How many entries match the filter per user (key: user id)
     *
     * @return array<string, int>
     */
    public function countsByUser(ActivityFilter $filter): array
    {
        return $this->contentRepositoryRegistry->get($this->contentRepositoryId())
            ->projectionState(ActivityFinder::class)
            ->countBy('user_id', $filter);
    }

    /**
     * How many entries match the filter per node type (key: node type name)
     *
     * @return array<string, int>
     */
    public function countsByNodeType(ActivityFilter $filter): array
    {
        return $this->contentRepositoryRegistry->get($this->contentRepositoryId())
            ->projectionState(ActivityFinder::class)
            ->countBy('node_type_name', $filter);
    }

    /**
     * @return list<string>
     */
    public function userIds(): array
    {
        return $this->contentRepositoryRegistry->get($this->contentRepositoryId())
            ->projectionState(ActivityFinder::class)
            ->findUserIds();
    }

    public function contentRepositoryId(): ContentRepositoryId
    {
        return ContentRepositoryId::fromString($this->contentRepository);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function entryOf(array $row, NodeTypeManager $nodeTypeManager): ActivityEntry
    {
        $nodeTypeName = (string)$row['node_type_name'];
        $changes = [];
        $decoded = is_string($row['changes']) ? json_decode($row['changes'], true) : null;
        foreach (is_array($decoded) ? $decoded : [] as $name => $change) {
            $name = (string)$name;
            $changes[] = new ActivityChange(
                $name,
                str_starts_with($name, '_') ? null : $this->propertyLabel($nodeTypeManager, $nodeTypeName, $name),
                $change['old'] ?? null,
                $change['new'] ?? null,
            );
        }
        $publish = $row['publish_sequence_number'];

        return new ActivityEntry(
            source: self::SOURCE,
            key: str_pad((string)$row['sequence_number'], self::KEY_LENGTH, '0', STR_PAD_LEFT),
            time: $this->utc((string)$row['recorded_at']) ?? new \DateTimeImmutable('@0'),
            initiatedAt: $this->utc((string)$row['initiated_at']),
            userId: $row['user_id'] === '' ? null : (string)$row['user_id'],
            action: (string)$row['action'],
            itemType: $nodeTypeName,
            itemId: (string)$row['node_aggregate_id'],
            itemLabel: is_string($row['label']) ? $row['label'] : null,
            documentId: is_string($row['document_node_aggregate_id']) ? $row['document_node_aggregate_id'] : null,
            documentLabel: is_string($row['document_label']) ? $row['document_label'] : null,
            documentExists: $row['document_removed'] !== null && !$row['document_removed'],
            changes: $changes,
            workspaceName: is_string($row['workspace_name']) ? $row['workspace_name'] : null,
            group: $publish === null ? null : self::SOURCE . ':publish:' . $publish,
            dimensionSpacePoint: is_string($row['origin']) ? $row['origin'] : null,
        );
    }

    private function propertyLabel(
        NodeTypeManager $nodeTypeManager,
        string $nodeTypeName,
        string $propertyName,
    ): ?string {
        $nodeType = $nodeTypeManager->getNodeType($nodeTypeName);
        if ($nodeType === null) {
            return null;
        }
        $label = $nodeType->getConfiguration('properties.' . $propertyName . '.ui.label')
            ?? $nodeType->getConfiguration('references.' . $propertyName . '.ui.label');
        if ($label === 'i18n') {
            // the Neos convention: Vendor.Package:NodeTypes.Name:properties.name (translated by the module)
            [$package, $name] = explode(':', $nodeTypeName, 2) + [1 => ''];
            return $package . ':NodeTypes.' . $name . ':properties.' . $propertyName;
        }
        return is_string($label) && $label !== '' ? $label : null;
    }

    private function utc(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $time === false ? null : $time;
    }
}
