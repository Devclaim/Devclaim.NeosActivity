<?php

declare(strict_types=1);

namespace Devclaim\NeosActivity\Compare;

use Devclaim\NeosActivity\ContentRepository\ActivityFinder;
use Devclaim\NeosActivity\ContentRepository\ContentActivitySource;
use Devclaim\NeosActivity\Domain\ActivityChange;
use Devclaim\NeosActivity\Domain\ActivityEntry;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValue;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollection;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;

/**
 * A node as it was before or after a change of the log: the node as it is now, with the changed properties set to
 * their old or new values. The other properties and the child nodes stay as they are now (their changes are entries
 * of their own). For the visual compare: the styleguide adapter renders the node of each side
 * (ActivityNodeReferenceResolver).
 */
#[Flow\Scope('singleton')]
final class NodeVersions
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Whether an entry can be compared visually: changed properties of a node that is still there
     */
    public function isComparable(ActivityEntry $entry): bool
    {
        return $entry->source === ContentActivitySource::SOURCE
            && in_array($entry->action, ['changed', 'created'], true)
            && $this->propertyChanges($entry) !== [];
    }

    public function apply(Node $node, ActivityEntry $entry, CompareSide $side): Node
    {
        $values = $node->properties->serialized();
        $unset = [];
        $set = [];
        foreach ($this->propertyChanges($entry) as $change) {
            $value = $side === CompareSide::Before ? $change->old : $change->new;
            $serialized = is_array($value) ? ($value['value'] ?? null) : null;
            if (
                is_array($value) && is_string($value['type'] ?? null)
                && (is_scalar($serialized) || is_array($serialized))
            ) {
                $set[$change->name] = SerializedPropertyValue::create($serialized, $value['type']);
            } else {
                $unset[] = $change->name;
            }
        }
        $values = $values->unsetProperties(PropertyNames::fromArray($unset))
            ->merge(SerializedPropertyValues::fromArray($set));
        $propertyConverter = $this->contentRepositoryRegistry->get($node->contentRepositoryId)
            ->projectionState(ActivityFinder::class)
            ->propertyConverter();

        return Node::create(
            $node->contentRepositoryId,
            $node->workspaceName,
            $node->dimensionSpacePoint,
            $node->aggregateId,
            $node->originDimensionSpacePoint,
            $node->classification,
            $node->nodeTypeName,
            new PropertyCollection($values, $propertyConverter),
            $node->name,
            $node->tags,
            $node->timestamps,
            $node->visibilityConstraints,
        );
    }

    /**
     * The changes of properties (not of references, the type or the position): their values are serialized property
     * values ({value, type}), an unset property is null
     *
     * @return list<ActivityChange>
     */
    private function propertyChanges(ActivityEntry $entry): array
    {
        $isProperty = static fn (mixed $value): bool => is_array($value) && is_string($value['type'] ?? null);

        return array_values(array_filter(
            $entry->changes,
            static fn (ActivityChange $change): bool => !str_starts_with($change->name, '_')
                && ($isProperty($change->old) || $isProperty($change->new))
        ));
    }
}
