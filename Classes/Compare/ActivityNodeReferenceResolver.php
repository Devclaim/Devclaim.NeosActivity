<?php

declare(strict_types=1);

namespace Devclaim\NeosActivity\Compare;

use Devclaim\NeosActivity\ContentRepository\ContentActivitySource;
use Devclaim\NeosActivity\Domain\ActivityEntry;
use Devclaim\NeosStyleguide\NodeReferences\NodeReferenceResolverInterface;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;

/**
 * The node previews of the activity log (node references of Devclaim.NeosStyleguide, prefix "activity"):
 * "activity:<sequence number>:before|after", the node of an entry as it was before or after its change (NodeVersions),
 * rendered by the adapter of the frontend. Only for who may see the log; also when the node or its page is hidden now.
 */
#[Flow\Scope('singleton')]
final class ActivityNodeReferenceResolver implements NodeReferenceResolverInterface
{
    public const PREFIX = 'activity';

    #[Flow\Inject]
    protected ContentActivitySource $contentActivitySource;

    #[Flow\Inject]
    protected NodeVersions $nodeVersions;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected PrivilegeManagerInterface $privilegeManager;

    /**
     * The node reference of an entry and side, for the node preview of the adapter
     */
    public static function reference(ActivityEntry $entry, CompareSide $side): string
    {
        return self::PREFIX . ':' . (ltrim($entry->key, '0') ?: '0') . ':' . $side->value;
    }

    public function resolve(string $reference): ?Node
    {
        if (!$this->privilegeManager->isPrivilegeTargetGranted('Devclaim.NeosActivity:Module')) {
            return null;
        }
        [$key, $sideValue] = explode(':', $reference, 2) + [1 => ''];
        $side = CompareSide::tryFrom($sideValue);
        $entry = $side === null ? null : $this->contentActivitySource->entry($key);
        if ($side === null || $entry === null || !$this->nodeVersions->isComparable($entry)) {
            return null;
        }

        $dimensionSpacePoint = $entry->dimensionSpacePoint === null
            ? DimensionSpacePoint::createWithoutDimensions()
            : DimensionSpacePoint::fromJsonString($entry->dimensionSpacePoint);
        // the node as it is live now, hidden or not (not when it was removed since)
        $node = $this->contentRepositoryRegistry->get($this->contentActivitySource->contentRepositoryId())
            ->getContentGraph(WorkspaceName::forLive())
            ->getSubgraph($dimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved())
            ->findNodeById(NodeAggregateId::fromString($entry->itemId));

        return $node === null ? null : $this->nodeVersions->apply($node, $entry, $side);
    }
}
