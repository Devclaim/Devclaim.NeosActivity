<?php

declare(strict_types=1);

namespace Devclaim\NeosActivity\ContentRepository;

use Devclaim\NeosActivity\Domain\ActivityCursor;
use Devclaim\NeosActivity\Domain\ActivityFilter;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\Projection\ProjectionStateInterface;

/**
 * Reads the activity projection: the state of ActivityProjection, from $contentRepository->projectionState()
 */
final readonly class ActivityFinder implements ProjectionStateInterface
{
    public function __construct(
        private Connection $dbal,
        private string $entriesTable,
        private string $nodesTable,
        private PropertyConverter $propertyConverter,
    ) {
    }

    /**
     * One entry by its sequence number, like a row of findEntries()
     *
     * @return array<string, mixed>|null
     */
    public function findEntry(int $sequenceNumber): ?array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->dbal->createQueryBuilder()
            ->select('e.*', 'd.removed AS document_removed')
            ->from($this->entriesTable, 'e')
            ->leftJoin(
                'e',
                '(SELECT node_aggregate_id, MAX(removed) AS removed FROM ' . $this->nodesTable
                    . ' GROUP BY node_aggregate_id)',
                'd',
                'd.node_aggregate_id = e.document_node_aggregate_id'
            )
            ->where('e.sequence_number = :sequence')
            ->setParameter('sequence', $sequenceNumber)
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    /**
     * Builds the property collection of a node with the old or new values of an entry (NodeVersions)
     *
     * @internal
     */
    public function propertyConverter(): PropertyConverter
    {
        return $this->propertyConverter;
    }

    /**
     * The newest entries after the cursor, newest first: by time, then by sequence number (the key of the source)
     *
     * @return list<array<string, mixed>> rows of the entries table, with "document_removed"
     */
    public function findEntries(ActivityFilter $filter, ?ActivityCursor $after, string $source, int $limit): array
    {
        if ($filter->itemTypes === []) {
            return [];
        }
        $query = $this->dbal->createQueryBuilder()
            ->select('e.*', 'd.removed AS document_removed')
            ->from($this->entriesTable, 'e')
            ->leftJoin(
                'e',
                '(SELECT node_aggregate_id, MAX(removed) AS removed FROM ' . $this->nodesTable
                    . ' GROUP BY node_aggregate_id)',
                'd',
                'd.node_aggregate_id = e.document_node_aggregate_id'
            )
            ->orderBy('e.recorded_at', 'DESC')
            ->addOrderBy('e.sequence_number', 'DESC')
            ->setMaxResults($limit);

        $this->applyFilter($query, $filter);
        if ($after !== null) {
            $time = $after->time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $query->setParameter('time', $time);
            match ($after->sameTimeFor($source)) {
                'all' => $query->andWhere('e.recorded_at <= :time'),
                'none' => $query->andWhere('e.recorded_at < :time'),
                'belowKey' => $query
                    ->andWhere('e.recorded_at < :time OR (e.recorded_at = :time AND e.sequence_number < :sequence)')
                    ->setParameter('sequence', (int)$after->key),
            };
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $query->executeQuery()->fetchAllAssociative();
        return $rows;
    }

    /**
     * How many entries match the filter, per user or per node type (the counts next to the filters)
     *
     * @param 'user_id'|'node_type_name' $column
     * @return array<string, int>
     */
    public function countBy(string $column, ActivityFilter $filter): array
    {
        if ($filter->itemTypes === []) {
            return [];
        }
        $query = $this->dbal->createQueryBuilder()
            ->select('e.' . $column . ' AS value', 'COUNT(*) AS number')
            ->from($this->entriesTable, 'e')
            ->groupBy('e.' . $column);
        $this->applyFilter($query, $filter);
        $counts = [];
        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            if (is_string($row['value']) && $row['value'] !== '') {
                $counts[$row['value']] = (int)$row['number'];
            }
        }
        return $counts;
    }

    private function applyFilter(QueryBuilder $query, ActivityFilter $filter): void
    {
        if ($filter->userId !== null) {
            $query->andWhere('e.user_id = :user')->setParameter('user', $filter->userId);
        }
        if ($filter->itemTypes !== null) {
            $query->andWhere('e.node_type_name IN (:types)')
                ->setParameter('types', $filter->itemTypes, ArrayParameterType::STRING);
        }
        if ($filter->itemId !== null) {
            $query->andWhere('e.node_aggregate_id = :item')->setParameter('item', $filter->itemId);
        }
        if ($filter->documentId !== null) {
            $query->andWhere('e.document_node_aggregate_id = :document')->setParameter('document', $filter->documentId);
        }
        if ($filter->actions !== null) {
            $actions = $filter->actions === [] ? [''] : $filter->actions;
            $query->andWhere('e.action IN (:actions)')
                ->setParameter('actions', $actions, ArrayParameterType::STRING);
        }
        if ($filter->since !== null) {
            $query->andWhere('e.recorded_at >= :since')
                ->setParameter('since', $filter->since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        }
        if ($filter->search !== null && trim($filter->search) !== '') {
            $search = '%' . addcslashes(trim($filter->search), '%_\\') . '%';
            $query->andWhere('e.label LIKE :search OR e.document_label LIKE :search')->setParameter('search', $search);
        }
    }

    /**
     * The users that appear in the log, for its filter
     *
     * @return list<string>
     */
    public function findUserIds(): array
    {
        return array_values(array_filter(
            $this->dbal->fetchFirstColumn(
                'SELECT DISTINCT user_id FROM ' . $this->entriesTable . ' WHERE user_id <> \'\''
            ),
            'is_string'
        ));
    }
}
