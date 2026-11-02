<?php

declare(strict_types=1);

namespace Devclaim\NeosActivity\ContentRepository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\EventStore\InitiatingEventMetadata;
use Neos\ContentRepository\Core\Feature\Common\EmbedsWorkspaceName;
use Neos\ContentRepository\Core\Feature\Common\PublishableToWorkspaceInterface;
use Neos\ContentRepository\Core\Feature\NodeCreation\Event\NodeAggregateWithNodeWasCreated;
use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Core\Feature\NodeMove\Event\NodeAggregateWasMoved;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Event\NodeReferencesWereSet;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Event\NodeAggregateWasRemoved;
use Neos\ContentRepository\Core\Feature\NodeRenaming\Event\NodeAggregateNameWasChanged;
use Neos\ContentRepository\Core\Feature\NodeTypeChange\Event\NodeAggregateTypeWasChanged;
use Neos\ContentRepository\Core\Feature\NodeVariation\Event\NodeGeneralizationVariantWasCreated;
use Neos\ContentRepository\Core\Feature\NodeVariation\Event\NodePeerVariantWasCreated;
use Neos\ContentRepository\Core\Feature\NodeVariation\Event\NodeSpecializationVariantWasCreated;
use Neos\ContentRepository\Core\Feature\RootNodeCreation\Event\RootNodeAggregateWithNodeWasCreated;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Event\SubtreeWasTagged;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Event\SubtreeWasUntagged;
use Neos\ContentRepository\Core\Feature\WorkspaceModification\Event\WorkspaceWasRemoved;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasDiscarded;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasPublished;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ProjectionInterface;
use Neos\ContentRepository\Core\Projection\ProjectionStatus;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Dbal\DbalSchemaDiff;
use Neos\ContentRepository\Dbal\DbalSchemaFactory;
use Neos\EventStore\Model\EventEnvelope;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Psr\Log\LoggerInterface;

/**
 * The activity log of the content: one row per change that reached the live workspace (_entries), with who did it,
 * when, the old and the new values, and the workspace it was published from. A projection like the content graph of
 * Neos: only it writes the tables, and they can be dropped and replayed from the events at any time.
 *
 * The events alone do not answer the questions of the log, so the projection keeps two tables more:
 *
 * - _nodes, the live state of every node as far as the log needs it (type, parent, page, properties, references,
 *   removed): a property change holds only the new values, the old ones and the page come from here. Removed nodes
 *   stay, marked, for the history (and later for restoring them).
 * - _drafts, the changes made in other workspaces (user and review workspaces): publishing copies the events to live
 *   with the original user and edit time (InitiatingEventMetadata), so a live event is matched to its draft by node,
 *   event type, user and edit time, which gives the workspace it came from. WorkspaceWasPublished then groups the
 *   entries of one publish.
 *
 * A failure here is logged and skipped, never thrown: the log must not keep editors from working (an exception would
 * fail the command of the editor after it was committed).
 *
 * @implements ProjectionInterface<ActivityFinder>
 */
final class ActivityProjection implements ProjectionInterface
{
    private const LABEL_LENGTH = 120;

    private readonly string $entriesTable;
    private readonly string $nodesTable;
    private readonly string $draftsTable;
    private readonly ActivityFinder $finder;

    /**
     * @param list<string> $labelProperties
     */
    public function __construct(
        private readonly Connection $dbal,
        string $tableNamePrefix,
        private readonly NodeTypeManager $nodeTypeManager,
        private readonly WorkspaceName $logWorkspaceName,
        private readonly array $labelProperties,
        private readonly LoggerInterface $logger,
        PropertyConverter $propertyConverter,
    ) {
        $this->entriesTable = $tableNamePrefix . '_entries';
        $this->nodesTable = $tableNamePrefix . '_nodes';
        $this->draftsTable = $tableNamePrefix . '_drafts';
        $this->finder = new ActivityFinder($dbal, $this->entriesTable, $this->nodesTable, $propertyConverter);
    }

    public function setUp(): void
    {
        foreach ($this->determineRequiredSqlStatements() as $statement) {
            $this->dbal->executeStatement($statement);
        }
    }

    public function status(): ProjectionStatus
    {
        try {
            $this->dbal->connect();
        } catch (\Throwable $e) {
            return ProjectionStatus::error(sprintf('Failed to connect to database: %s', $e->getMessage()));
        }
        try {
            $requiredSqlStatements = $this->determineRequiredSqlStatements();
        } catch (\Throwable $e) {
            return ProjectionStatus::error(
                sprintf('Failed to determine required SQL statements: %s', $e->getMessage())
            );
        }
        if ($requiredSqlStatements !== []) {
            return ProjectionStatus::setupRequired(
                sprintf('Required SQL statements: %s', implode(chr(10), $requiredSqlStatements))
            );
        }
        return ProjectionStatus::ok();
    }

    public function resetState(): void
    {
        foreach ([$this->entriesTable, $this->nodesTable, $this->draftsTable] as $table) {
            $this->dbal->executeStatement('DELETE FROM ' . $table);
        }
    }

    public function getState(): ActivityFinder
    {
        return $this->finder;
    }

    public function apply(EventInterface $event, EventEnvelope $eventEnvelope): void
    {
        try {
            $this->applyEvent($event, $eventEnvelope);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Activity log: event %d (%s) skipped: %s',
                $eventEnvelope->sequenceNumber->value,
                $event::class,
                $e->getMessage()
            ), ['exception' => $e]);
        }
    }

    private function applyEvent(EventInterface $event, EventEnvelope $eventEnvelope): void
    {
        if ($event instanceof WorkspaceWasPublished) {
            $this->whenWorkspaceWasPublished($event, $eventEnvelope);
            return;
        }
        if ($event instanceof WorkspaceWasDiscarded) {
            // a partial discard applies the remaining changes again, they stay drafts
            if (!$event->partial) {
                $this->removeDrafts($event->workspaceName);
            }
            return;
        }
        if ($event instanceof WorkspaceWasRemoved) {
            $this->removeDrafts($event->workspaceName);
            return;
        }
        if (!$event instanceof EmbedsWorkspaceName) {
            return;
        }
        if ($event->getWorkspaceName()->equals($this->logWorkspaceName)) {
            $this->whenLiveEvent($event, $eventEnvelope);
        } elseif ($event instanceof PublishableToWorkspaceInterface) {
            $this->recordDraft($event, $eventEnvelope);
        }
    }

    private function whenLiveEvent(EventInterface $event, EventEnvelope $eventEnvelope): void
    {
        match ($event::class) {
            RootNodeAggregateWithNodeWasCreated::class => $this->whenRootNodeAggregateWithNodeWasCreated($event),
            NodeAggregateWithNodeWasCreated::class
                => $this->whenNodeAggregateWithNodeWasCreated($event, $eventEnvelope),
            NodePropertiesWereSet::class => $this->whenNodePropertiesWereSet($event, $eventEnvelope),
            NodeReferencesWereSet::class => $this->whenNodeReferencesWereSet($event, $eventEnvelope),
            NodeAggregateWasMoved::class => $this->whenNodeAggregateWasMoved($event, $eventEnvelope),
            NodeAggregateWasRemoved::class => $this->whenNodeAggregateWasRemoved($event, $eventEnvelope),
            SubtreeWasTagged::class, SubtreeWasUntagged::class => $this->whenSubtreeTagChanged($event, $eventEnvelope),
            NodeAggregateTypeWasChanged::class => $this->whenNodeAggregateTypeWasChanged($event, $eventEnvelope),
            NodeAggregateNameWasChanged::class => $this->whenNodeAggregateNameWasChanged($event, $eventEnvelope),
            NodePeerVariantWasCreated::class
                => $this->whenVariantWasCreated($event, $event->peerOrigin, $eventEnvelope),
            NodeSpecializationVariantWasCreated::class
                => $this->whenVariantWasCreated($event, $event->specializationOrigin, $eventEnvelope),
            NodeGeneralizationVariantWasCreated::class
                => $this->whenVariantWasCreated($event, $event->generalizationOrigin, $eventEnvelope),
            // dimension adjustments and the like are no edits
            default => null,
        };
    }

    private function whenRootNodeAggregateWithNodeWasCreated(RootNodeAggregateWithNodeWasCreated $event): void
    {
        $this->insertNode(
            $event->nodeAggregateId,
            OriginDimensionSpacePoint::createWithoutDimensions(),
            $event->nodeTypeName,
            null,
            null,
            [],
        );
    }

    private function whenNodeAggregateWithNodeWasCreated(
        NodeAggregateWithNodeWasCreated $event,
        EventEnvelope $eventEnvelope,
    ): void {
        $properties = $this->plain($event->initialPropertyValues);
        $documentId = $this->isDocumentType($event->nodeTypeName)
            ? $event->nodeAggregateId->value
            : $this->documentOf($event->parentNodeAggregateId->value);
        $this->insertNode(
            $event->nodeAggregateId,
            $event->originDimensionSpacePoint,
            $event->nodeTypeName,
            $event->parentNodeAggregateId->value,
            $documentId,
            $properties,
        );
        if (!$event->nodeReferences->isEmpty()) {
            $references = $this->referencesByName($event->nodeReferences->jsonSerialize());
            $this->dbal->update($this->nodesTable, [
                'node_references' => json_encode($references, JSON_THROW_ON_ERROR),
            ], [
                'node_aggregate_id' => $event->nodeAggregateId->value,
                'origin_hash' => $event->originDimensionSpacePoint->hash,
            ]);
        }
        // tethered nodes (content collections and the like) come with their parent, they are part of its creation
        if ($event->nodeAggregateClassification->isTethered()) {
            return;
        }
        $changes = [];
        foreach ($properties as $name => $value) {
            $changes[$name] = ['old' => null, 'new' => $value];
        }
        $this->insertEntry(
            $eventEnvelope,
            $event,
            'created',
            $event->nodeAggregateId->value,
            $event->originDimensionSpacePoint,
            $changes
        );
    }

    private function whenNodePropertiesWereSet(NodePropertiesWereSet $event, EventEnvelope $eventEnvelope): void
    {
        $node = $this->findNode($event->nodeAggregateId->value, $event->originDimensionSpacePoint->hash);
        $properties = $node === null ? [] : $this->decode($node['properties']);
        $changes = [];
        foreach ($this->plain($event->propertyValues) as $name => $value) {
            $old = $properties[$name] ?? null;
            if ($old != $value) {
                $changes[$name] = ['old' => $old, 'new' => $value];
            }
            $properties[$name] = $value;
        }
        foreach ($event->propertiesToUnset as $name) {
            if (isset($properties[$name->value])) {
                $changes[$name->value] = ['old' => $properties[$name->value], 'new' => null];
            }
            unset($properties[$name->value]);
        }
        if ($node !== null) {
            $this->dbal->update($this->nodesTable, ['properties' => json_encode($properties, JSON_THROW_ON_ERROR)], [
                'node_aggregate_id' => $event->nodeAggregateId->value,
                'origin_hash' => $event->originDimensionSpacePoint->hash,
            ]);
        }
        // the same values again (a save without a change) are no activity
        if ($changes !== []) {
            $nodeId = $event->nodeAggregateId->value;
            $this->insertEntry($eventEnvelope, $event, 'changed', $nodeId, $event->originDimensionSpacePoint, $changes);
        }
    }

    private function whenNodeReferencesWereSet(NodeReferencesWereSet $event, EventEnvelope $eventEnvelope): void
    {
        $newReferences = $this->referencesByName($event->references->jsonSerialize());
        $changes = [];
        $firstOrigin = null;
        foreach ($event->affectedSourceOriginDimensionSpacePoints as $origin) {
            // the entry shows the change of the first variant (most sites have one language)
            $isFirst = $firstOrigin === null;
            $firstOrigin ??= $origin;
            $node = $this->findNode($event->nodeAggregateId->value, $origin->hash);
            if ($node === null) {
                continue;
            }
            $references = $this->decode($node['node_references']);
            foreach ($newReferences as $name => $targets) {
                if ($isFirst && ($references[$name] ?? []) != $targets) {
                    $changes[$name] = ['old' => $references[$name] ?? [], 'new' => $targets];
                }
                $references[$name] = $targets;
            }
            $this->dbal->update($this->nodesTable, [
                'node_references' => json_encode($references, JSON_THROW_ON_ERROR),
            ], [
                'node_aggregate_id' => $event->nodeAggregateId->value,
                'origin_hash' => $origin->hash,
            ]);
        }
        if ($changes !== []) {
            $this->insertEntry(
                $eventEnvelope,
                $event,
                'changed',
                $event->nodeAggregateId->value,
                $firstOrigin,
                $changes
            );
        }
    }

    private function whenNodeAggregateWasMoved(NodeAggregateWasMoved $event, EventEnvelope $eventEnvelope): void
    {
        $nodeId = $event->nodeAggregateId->value;
        $node = $this->findAnyNode($nodeId);
        if ($event->newParentNodeAggregateId === null) {
            // a new position below the same parent
            $this->insertEntry($eventEnvelope, $event, 'sorted', $nodeId, null, []);
            return;
        }
        $newParentId = $event->newParentNodeAggregateId->value;
        $this->dbal->update(
            $this->nodesTable,
            ['parent_node_aggregate_id' => $newParentId],
            ['node_aggregate_id' => $nodeId]
        );
        if ($node !== null && $node['document_node_aggregate_id'] !== $nodeId) {
            // content moved to another page: it and its content belong to the page of the new parent now
            $this->setDocumentOfSubtree($nodeId, $this->documentOf($newParentId));
        }
        $this->insertEntry($eventEnvelope, $event, 'moved', $nodeId, null, [
            '_parent' => ['old' => $node['parent_node_aggregate_id'] ?? null, 'new' => $newParentId],
        ]);
    }

    private function whenNodeAggregateWasRemoved(NodeAggregateWasRemoved $event, EventEnvelope $eventEnvelope): void
    {
        $nodeId = $event->nodeAggregateId->value;
        $node = $this->findAnyNode($nodeId);
        $this->setRemovedOfSubtree($nodeId, true);
        // Neos removes softly first (the tag "removed", an entry of its own) and later for good, by the system: the
        // second is housekeeping, not an edit
        if ($node === null || !$node['removed']) {
            $this->insertEntry($eventEnvelope, $event, 'removed', $nodeId, null, []);
        }
    }

    private function whenSubtreeTagChanged(
        SubtreeWasTagged|SubtreeWasUntagged $event,
        EventEnvelope $eventEnvelope,
    ): void {
        $nodeId = $event->nodeAggregateId->value;
        $tag = $event->tag->value;
        $tagged = $event instanceof SubtreeWasTagged;
        $action = match ($tag) {
            'disabled' => $tagged ? 'hidden' : 'shown',
            'removed' => $tagged ? 'removed' : 'restored',
            default => $tagged ? 'tagged' : 'untagged',
        };
        if ($tag === 'removed') {
            $this->setRemovedOfSubtree($nodeId, $tagged);
        }
        $changes = in_array($action, ['tagged', 'untagged'], true)
            ? ['_tag' => ['old' => $tagged ? null : $tag, 'new' => $tagged ? $tag : null]]
            : [];
        $this->insertEntry($eventEnvelope, $event, $action, $nodeId, null, $changes);
    }

    private function whenNodeAggregateTypeWasChanged(
        NodeAggregateTypeWasChanged $event,
        EventEnvelope $eventEnvelope,
    ): void {
        $nodeId = $event->nodeAggregateId->value;
        $old = $this->findAnyNode($nodeId)['node_type_name'] ?? null;
        $this->dbal->update(
            $this->nodesTable,
            ['node_type_name' => $event->newNodeTypeName->value],
            ['node_aggregate_id' => $nodeId]
        );
        $this->insertEntry($eventEnvelope, $event, 'changed', $nodeId, null, [
            '_nodeType' => ['old' => $old, 'new' => $event->newNodeTypeName->value],
        ]);
    }

    private function whenNodeAggregateNameWasChanged(
        NodeAggregateNameWasChanged $event,
        EventEnvelope $eventEnvelope,
    ): void {
        $this->insertEntry($eventEnvelope, $event, 'changed', $event->nodeAggregateId->value, null, [
            '_nodeName' => ['old' => null, 'new' => $event->newNodeName->value],
        ]);
    }

    private function whenVariantWasCreated(
        NodePeerVariantWasCreated|NodeSpecializationVariantWasCreated|NodeGeneralizationVariantWasCreated $event,
        OriginDimensionSpacePoint $variantOrigin,
        EventEnvelope $eventEnvelope,
    ): void {
        $nodeAggregateId = $event->nodeAggregateId;
        $sourceOrigin = $event->sourceOrigin;
        $source = $this->findNode($nodeAggregateId->value, $sourceOrigin->hash);
        if ($source === null) {
            return;
        }
        $this->dbal->delete($this->nodesTable, [
            'node_aggregate_id' => $nodeAggregateId->value,
            'origin_hash' => $variantOrigin->hash,
        ]);
        $this->dbal->insert($this->nodesTable, [
            ...$source,
            'origin_hash' => $variantOrigin->hash,
            'origin' => $variantOrigin->toJson(),
        ]);
        $this->insertEntry($eventEnvelope, $event, 'translated', $nodeAggregateId->value, $variantOrigin, [
            '_dimension' => ['old' => $sourceOrigin->toJson(), 'new' => $variantOrigin->toJson()],
        ]);
    }

    private function whenWorkspaceWasPublished(WorkspaceWasPublished $event, EventEnvelope $eventEnvelope): void
    {
        if (!$event->targetWorkspaceName->equals($this->logWorkspaceName)) {
            // published into another workspace: still drafts, now of that one (its copies of the events were recorded
            // as its drafts); the log names that workspace when they reach live
            if (!$event->partial) {
                $this->removeDrafts($event->sourceWorkspaceName);
            }
            return;
        }
        // the entries this publish brought to live (matched to their drafts in insertEntry) form one group
        $this->dbal->executeStatement(
            'UPDATE ' . $this->entriesTable . ' SET publish_sequence_number = :publish
                WHERE workspace_name = :workspace AND publish_sequence_number IS NULL',
            ['publish' => $eventEnvelope->sequenceNumber->value, 'workspace' => $event->sourceWorkspaceName->value]
        );
        if (!$event->partial) {
            $this->removeDrafts($event->sourceWorkspaceName);
            return;
        }
        // a partial publish: the drafts that were published are gone, the others stay (and were applied again)
        $this->dbal->executeStatement(
            'DELETE FROM ' . $this->draftsTable . ' WHERE workspace_name = :workspace AND EXISTS (
                SELECT 1 FROM ' . $this->entriesTable . ' e WHERE e.publish_sequence_number = :publish
                AND e.node_aggregate_id = ' . $this->draftsTable . '.node_aggregate_id
                AND e.event_type = ' . $this->draftsTable . '.event_type
                AND e.user_id = ' . $this->draftsTable . '.user_id
                AND e.initiated_at = ' . $this->draftsTable . '.initiated_at)',
            ['publish' => $eventEnvelope->sequenceNumber->value, 'workspace' => $event->sourceWorkspaceName->value]
        );
    }

    private function recordDraft(EventInterface&EmbedsWorkspaceName $event, EventEnvelope $eventEnvelope): void
    {
        $nodeId = $this->nodeAggregateIdOf($event);
        if ($nodeId === null) {
            return;
        }
        $draft = [
            'workspace_name' => $event->getWorkspaceName()->value,
            'node_aggregate_id' => $nodeId,
            'event_type' => $this->eventType($event),
            'user_id' => $this->userId($eventEnvelope) ?? '',
            'initiated_at' => $this->initiatedAt($eventEnvelope) ?? '',
        ];
        // the same change can arrive again (applied again after a partial publish or a rebase)
        $exists = $this->dbal->fetchOne(
            'SELECT 1 FROM ' . $this->draftsTable . ' WHERE workspace_name = :workspace_name
                AND node_aggregate_id = :node_aggregate_id AND event_type = :event_type AND user_id = :user_id
                AND initiated_at = :initiated_at',
            $draft
        );
        if ($exists === false) {
            $this->dbal->insert($this->draftsTable, $draft);
        }
    }

    private function removeDrafts(WorkspaceName $workspaceName): void
    {
        $this->dbal->delete($this->draftsTable, ['workspace_name' => $workspaceName->value]);
    }

    /**
     * @param array<string, array{old: mixed, new: mixed}> $changes
     */
    private function insertEntry(
        EventEnvelope $eventEnvelope,
        EventInterface $event,
        string $action,
        string $nodeId,
        ?OriginDimensionSpacePoint $origin,
        array $changes,
    ): void {
        $node = $origin === null ? $this->findAnyNode($nodeId) : $this->findNode($nodeId, $origin->hash);
        $documentId = $node['document_node_aggregate_id'] ?? null;
        $document = $documentId === null ? null : $this->findAnyNode($documentId);
        $userId = $this->userId($eventEnvelope) ?? '';
        $initiatedAt = $this->initiatedAt($eventEnvelope) ?? '';
        $eventType = $this->eventType($event);

        $this->dbal->insert($this->entriesTable, [
            'sequence_number' => $eventEnvelope->sequenceNumber->value,
            'recorded_at' => $eventEnvelope->recordedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'initiated_at' => $initiatedAt,
            'user_id' => $userId,
            'event_type' => $eventType,
            'action' => $action,
            'node_aggregate_id' => $nodeId,
            'node_type_name' => $node['node_type_name'] ?? '',
            'origin' => $origin?->toJson() ?? ($node['origin'] ?? null),
            'label' => $node === null ? null : $this->labelOf($node),
            'document_node_aggregate_id' => $documentId,
            'document_label' => $document === null ? null : $this->labelOf($document),
            'changes' => $changes === [] ? null : json_encode($changes, JSON_THROW_ON_ERROR),
            'workspace_name' => $this->draftWorkspace($nodeId, $eventType, $userId, $initiatedAt),
            'publish_sequence_number' => null,
        ]);
    }

    private function draftWorkspace(string $nodeId, string $eventType, string $userId, string $initiatedAt): ?string
    {
        $workspaceName = $this->dbal->fetchOne(
            'SELECT workspace_name FROM ' . $this->draftsTable . ' WHERE node_aggregate_id = :node
                AND event_type = :type AND user_id = :user AND initiated_at = :initiated LIMIT 1',
            ['node' => $nodeId, 'type' => $eventType, 'user' => $userId, 'initiated' => $initiatedAt]
        );
        return is_string($workspaceName) ? $workspaceName : null;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function insertNode(
        NodeAggregateId $nodeAggregateId,
        OriginDimensionSpacePoint $origin,
        NodeTypeName $nodeTypeName,
        ?string $parentId,
        ?string $documentId,
        array $properties,
    ): void {
        $this->dbal->delete($this->nodesTable, [
            'node_aggregate_id' => $nodeAggregateId->value,
            'origin_hash' => $origin->hash,
        ]);
        $this->dbal->insert($this->nodesTable, [
            'node_aggregate_id' => $nodeAggregateId->value,
            'origin_hash' => $origin->hash,
            'origin' => $origin->toJson(),
            'node_type_name' => $nodeTypeName->value,
            'parent_node_aggregate_id' => $parentId,
            'document_node_aggregate_id' => $documentId,
            'properties' => json_encode($properties, JSON_THROW_ON_ERROR),
            'node_references' => '{}',
            'removed' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findNode(string $nodeId, string $originHash): ?array
    {
        $row = $this->dbal->fetchAssociative(
            'SELECT * FROM ' . $this->nodesTable . ' WHERE node_aggregate_id = :node AND origin_hash = :origin',
            ['node' => $nodeId, 'origin' => $originHash]
        );
        return $row === false ? null : $row;
    }

    /**
     * Any variant of the node: what the variants share (type, parent, page), and a label (there is one language only
     * on most sites)
     *
     * @return array<string, mixed>|null
     */
    private function findAnyNode(string $nodeId): ?array
    {
        $row = $this->dbal->fetchAssociative(
            'SELECT * FROM ' . $this->nodesTable . ' WHERE node_aggregate_id = :node LIMIT 1',
            ['node' => $nodeId]
        );
        return $row === false ? null : $row;
    }

    private function documentOf(string $nodeId): ?string
    {
        $document = $this->findAnyNode($nodeId)['document_node_aggregate_id'] ?? null;
        return is_string($document) ? $document : null;
    }

    private function isDocumentType(NodeTypeName $nodeTypeName): bool
    {
        return $this->nodeTypeManager->getNodeType($nodeTypeName)?->isOfType('Neos.Neos:Document') ?? false;
    }

    /**
     * Sets the page of a moved content node and of its content; nodes below that are pages themselves keep theirs
     */
    private function setDocumentOfSubtree(string $nodeId, ?string $documentId): void
    {
        $queue = [$nodeId];
        while ($queue !== []) {
            $current = array_shift($queue);
            $this->dbal->update(
                $this->nodesTable,
                ['document_node_aggregate_id' => $documentId],
                ['node_aggregate_id' => $current]
            );
            $children = $this->dbal->fetchFirstColumn(
                'SELECT DISTINCT node_aggregate_id FROM ' . $this->nodesTable . '
                    WHERE parent_node_aggregate_id = :parent
                    AND (document_node_aggregate_id IS NULL OR document_node_aggregate_id <> node_aggregate_id)',
                ['parent' => $current]
            );
            array_push($queue, ...array_filter($children, 'is_string'));
        }
    }

    private function setRemovedOfSubtree(string $nodeId, bool $removed): void
    {
        $queue = [$nodeId];
        $seen = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            $this->dbal->update($this->nodesTable, ['removed' => $removed ? 1 : 0], ['node_aggregate_id' => $current]);
            $children = $this->dbal->fetchFirstColumn(
                'SELECT DISTINCT node_aggregate_id FROM ' . $this->nodesTable
                    . ' WHERE parent_node_aggregate_id = :parent',
                ['parent' => $current]
            );
            array_push($queue, ...array_filter($children, 'is_string'));
        }
    }

    /**
     * The name of a node in the log: the first label property that is set, as plain text
     *
     * @param array<string, mixed> $node
     */
    private function labelOf(array $node): ?string
    {
        $properties = $this->decode($node['properties']);
        foreach ($this->labelProperties as $name) {
            $value = $properties[$name]['value'] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $plain = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5);
            $text = trim(preg_replace('/\s+/u', ' ', $plain) ?? '');
            if ($text !== '') {
                return mb_strlen($text) > self::LABEL_LENGTH
                    ? mb_substr($text, 0, self::LABEL_LENGTH - 1) . '…'
                    : $text;
            }
        }
        return null;
    }

    /**
     * The references as {name: [target, …]}, without their properties (the log shows which nodes are referenced)
     *
     * @param mixed $serialized SerializedNodeReferences::jsonSerialize()
     * @return array<string, list<string>>
     */
    private function referencesByName(mixed $serialized): array
    {
        $plain = json_decode(json_encode($serialized, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $byName = [];
        foreach (is_array($plain) ? $plain : [] as $forName) {
            if (!is_array($forName) || !is_string($forName['referenceName'] ?? null)) {
                continue;
            }
            $targets = [];
            foreach (is_array($forName['references'] ?? null) ? $forName['references'] : [] as $reference) {
                if (is_array($reference) && is_string($reference['target'] ?? null)) {
                    $targets[] = $reference['target'];
                }
            }
            $byName[$forName['referenceName']] = $targets;
        }
        return $byName;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function nodeAggregateIdOf(EventInterface $event): ?string
    {
        $nodeAggregateId = property_exists($event, 'nodeAggregateId') ? $event->nodeAggregateId : null;
        return $nodeAggregateId instanceof NodeAggregateId ? $nodeAggregateId->value : null;
    }

    /**
     * The short class name of the event, the same in the draft and in its copy in live (also for legacy events, which
     * are read as their successors: NodeAggregateWasDisabled as SubtreeWasTagged)
     */
    private function eventType(EventInterface $event): string
    {
        $position = strrpos($event::class, '\\');
        return $position === false ? $event::class : substr($event::class, $position + 1);
    }

    /**
     * Serialized property values as plain arrays ({name: {value, type}}), as they are stored and compared
     *
     * @return array<string, mixed>
     */
    private function plain(\JsonSerializable $values): array
    {
        $plain = json_decode(json_encode($values, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        return is_array($plain) ? $plain : [];
    }

    private function userId(EventEnvelope $eventEnvelope): ?string
    {
        $metadata = $eventEnvelope->event->metadata;
        $userId = $metadata?->get(InitiatingEventMetadata::INITIATING_USER_ID);
        return is_string($userId) ? $userId : null;
    }

    /**
     * When the user made the change, in UTC like recorded_at
     */
    private function initiatedAt(EventEnvelope $eventEnvelope): ?string
    {
        $metadata = $eventEnvelope->event->metadata;
        $timestamp = $metadata?->get(InitiatingEventMetadata::INITIATING_TIMESTAMP);
        if (!is_string($timestamp)) {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp);
        return $time === false ? null : $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @return list<string>
     */
    private function determineRequiredSqlStatements(): array
    {
        $platform = $this->dbal->getDatabasePlatform();
        $string = static fn (string $name, int $length): Column => (new Column($name, Type::getType(Types::STRING)))
            ->setLength($length);

        $entries = new Table($this->entriesTable, [
            (new Column('sequence_number', Type::getType(Types::BIGINT)))->setUnsigned(true),
            $string('recorded_at', 19),
            $string('initiated_at', 19)->setDefault(''),
            $string('user_id', 64)->setDefault(''),
            $string('event_type', 64),
            $string('action', 32),
            DbalSchemaFactory::columnForNodeAggregateId('node_aggregate_id', $platform)->setNotnull(true),
            DbalSchemaFactory::columnForNodeTypeName('node_type_name', $platform),
            DbalSchemaFactory::columnForGenericText('origin', $platform)->setNotnull(false),
            DbalSchemaFactory::columnForGenericString('label', $platform)->setLength(255)->setNotnull(false),
            DbalSchemaFactory::columnForNodeAggregateId('document_node_aggregate_id', $platform)->setNotnull(false),
            DbalSchemaFactory::columnForGenericString('document_label', $platform)->setLength(255)->setNotnull(false),
            DbalSchemaFactory::columnForProperties('changes', $platform)->setNotnull(false),
            DbalSchemaFactory::columnForWorkspaceName('workspace_name', $platform)->setNotnull(false),
            (new Column('publish_sequence_number', Type::getType(Types::BIGINT)))->setUnsigned(true)->setNotnull(false),
        ]);
        $entries->setPrimaryKey(['sequence_number']);
        $entries->addIndex(['recorded_at'], 'by_time');
        $entries->addIndex(['user_id'], 'by_user');
        $entries->addIndex(['node_aggregate_id'], 'by_node');
        $entries->addIndex(['document_node_aggregate_id'], 'by_document');
        $entries->addIndex(['node_type_name'], 'by_node_type');
        $entries->addIndex(['workspace_name', 'publish_sequence_number'], 'by_publish');

        $nodes = new Table($this->nodesTable, [
            DbalSchemaFactory::columnForNodeAggregateId('node_aggregate_id', $platform)->setNotnull(true),
            DbalSchemaFactory::columnForDimensionSpacePointHash('origin_hash', $platform)->setNotnull(true),
            DbalSchemaFactory::columnForGenericText('origin', $platform),
            DbalSchemaFactory::columnForNodeTypeName('node_type_name', $platform),
            DbalSchemaFactory::columnForNodeAggregateId('parent_node_aggregate_id', $platform)->setNotnull(false),
            DbalSchemaFactory::columnForNodeAggregateId('document_node_aggregate_id', $platform)->setNotnull(false),
            DbalSchemaFactory::columnForProperties('properties', $platform),
            DbalSchemaFactory::columnForProperties('node_references', $platform),
            (new Column('removed', Type::getType(Types::BOOLEAN)))->setDefault(false),
        ]);
        $nodes->setPrimaryKey(['node_aggregate_id', 'origin_hash']);
        $nodes->addIndex(['parent_node_aggregate_id'], 'by_parent');

        $drafts = new Table($this->draftsTable, [
            DbalSchemaFactory::columnForWorkspaceName('workspace_name', $platform),
            DbalSchemaFactory::columnForNodeAggregateId('node_aggregate_id', $platform)->setNotnull(true),
            $string('event_type', 64),
            $string('user_id', 64)->setDefault(''),
            $string('initiated_at', 19)->setDefault(''),
        ]);
        $drafts->setPrimaryKey(['workspace_name', 'node_aggregate_id', 'event_type', 'user_id', 'initiated_at']);
        $drafts->addIndex(['node_aggregate_id', 'event_type', 'user_id', 'initiated_at'], 'by_change');

        $schema = DbalSchemaFactory::createSchemaWithTables($this->dbal, [$entries, $nodes, $drafts]);
        return array_values(DbalSchemaDiff::determineRequiredSqlStatements($this->dbal, $schema));
    }
}
