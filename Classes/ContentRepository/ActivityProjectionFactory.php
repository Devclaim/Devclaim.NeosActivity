<?php

declare(strict_types=1);

namespace Devclaim\NeosActivity\ContentRepository;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Factory\SubscriberFactoryDependencies;
use Neos\ContentRepository\Core\Projection\ProjectionFactoryInterface;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Psr\Log\LoggerInterface;

/**
 * Builds the activity projection (Settings.yaml: Neos.ContentRepositoryRegistry.presets.default.projections), its
 * tables named after the content repository like those of Neos (cr_default_p_devclaim_activity_*).
 *
 * @implements ProjectionFactoryInterface<ActivityProjection>
 */
final class ActivityProjectionFactory implements ProjectionFactoryInterface
{
    public function __construct(
        private readonly Connection $dbal,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $options workspaceName (live), labelProperties
     */
    public function build(
        SubscriberFactoryDependencies $projectionFactoryDependencies,
        array $options,
    ): ActivityProjection {
        $workspaceName = $options['workspaceName'] ?? null;
        $labelProperties = $options['labelProperties'] ?? null;
        return new ActivityProjection(
            $this->dbal,
            sprintf('cr_%s_p_devclaim_activity', $projectionFactoryDependencies->contentRepositoryId->value),
            $projectionFactoryDependencies->nodeTypeManager,
            is_string($workspaceName) ? WorkspaceName::fromString($workspaceName) : WorkspaceName::forLive(),
            is_array($labelProperties) ? array_values(array_filter($labelProperties, 'is_string')) : ['title'],
            $this->logger,
            $projectionFactoryDependencies->getPropertyConverter(),
        );
    }
}
