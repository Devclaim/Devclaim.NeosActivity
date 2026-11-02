<?php

declare(strict_types=1);

namespace Devclaim\NeosActivity\Controller;

use Devclaim\NeosActivity\Application\ActivityLog;
use Devclaim\NeosActivity\Compare\ActivityNodeReferenceResolver;
use Devclaim\NeosActivity\Compare\CompareSide;
use Devclaim\NeosActivity\Compare\NodeVersions;
use Devclaim\NeosActivity\ContentRepository\ContentActivitySource;
use Devclaim\NeosActivity\Domain\ActivityChange;
use Devclaim\NeosActivity\Domain\ActivityCursor;
use Devclaim\NeosActivity\Domain\ActivityEntry;
use Devclaim\NeosActivity\Domain\ActivityFilter;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Domain\Model\UserId;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;

/**
 * The backend module "Aktivität" (management/activity): the log, newest first, grouped by publish, with filters by
 * user, kind of item, page and item. An entry opens to its changes: the changed node before and after, rendered by
 * the website (the node preview of the styleguide adapter, ActivityNodeReferenceResolver), and the changed fields.
 * Read only for now.
 */
final class ActivityController extends AbstractModuleController
{
    private const VALUE_LENGTH = 300;
    private const GROUP_ENTRIES_SHOWN = 5;
    /** the values compared word by word in the browser (Module.js) */
    private const TEXT_LENGTH = 5000;
    private const PACKAGE = 'Devclaim.NeosActivity';

    /** Font Awesome icons of the actions (the Neos backend's icon set) */
    private const ICONS = [
        'created' => 'fas fa-plus',
        'changed' => 'fas fa-pencil-alt',
        'moved' => 'fas fa-arrows-alt',
        'sorted' => 'fas fa-sort',
        'hidden' => 'fas fa-eye-slash',
        'shown' => 'fas fa-eye',
        'removed' => 'fas fa-trash-alt',
        'restored' => 'fas fa-trash-restore',
        'tagged' => 'fas fa-tag',
        'untagged' => 'fas fa-tag',
        'translated' => 'fas fa-language',
    ];

    /** the filter "Zeitraum": since when (relative to now) */
    private const PERIODS = [
        'today' => 'today',
        '7d' => '-7 days',
        '30d' => '-30 days',
    ];

    /** the filter "Was": the actions of each choice */
    private const WHATS = [
        'changed' => ['changed', 'moved', 'sorted', 'translated', 'tagged', 'untagged'],
        'created' => ['created'],
        'hidden' => ['hidden', 'shown', 'removed', 'restored'],
    ];

    protected $defaultViewObjectName = FusionView::class;

    #[Flow\Inject]
    protected ActivityLog $activityLog;

    #[Flow\Inject]
    protected ContentActivitySource $contentActivitySource;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected NodeVersions $nodeVersions;

    /**
     * The frontend configuration of the Neos UI: the adapter of the styleguide ("Devclaim.NeosStyleguide" › adapter),
     * whose manifest names the page of the node previews (nodePreview)
     *
     * @var array<string, mixed>
     */
    #[Flow\InjectConfiguration(path: 'Ui.frontendConfiguration', package: 'Neos.Neos')]
    protected array $uiFrontendConfiguration = [];

    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected WorkspaceService $workspaceService;

    #[Flow\Inject]
    protected Translator $translator;

    /**
     * @var array<string, array{label?: string, nodeTypes?: list<string>}>
     */
    #[Flow\InjectConfiguration(path: 'itemTypes', package: 'Devclaim.NeosActivity')]
    protected array $itemTypes = [];

    #[Flow\InjectConfiguration(path: 'pageSize', package: 'Devclaim.NeosActivity')]
    protected int $pageSize = 50;

    /** @var array<string, string> */
    private array $userLabels = [];

    /** @var array<string, string> */
    private array $workspaceLabels = [];

    public function indexAction(
        string $user = '',
        string $type = '',
        string $document = '',
        string $item = '',
        string $after = '',
        string $period = '',
        string $what = '',
        string $q = '',
    ): void {
        $period = isset(self::PERIODS[$period]) ? $period : '';
        $what = isset(self::WHATS[$what]) ? $what : '';
        $q = trim(mb_substr($q, 0, 100));
        $filter = new ActivityFilter(
            userId: $user === '' ? null : $user,
            itemTypes: $type === '' ? null : ($this->itemTypes[$type]['nodeTypes'] ?? []),
            itemId: $item === '' ? null : $item,
            documentId: $document === '' ? null : $document,
            actions: $what === '' ? null : self::WHATS[$what],
            since: $period === '' ? null : new \DateTimeImmutable(self::PERIODS[$period]),
            search: $q === '' ? null : $q,
        );
        $page = $this->activityLog->page($filter, ActivityCursor::tryFromString($after), $this->pageSize);
        $current = [
            'user' => $user,
            'type' => $type,
            'document' => $document,
            'item' => $item,
            'period' => $period,
            'what' => $what,
            'q' => $q,
        ];

        $this->view->assignMultiple([
            'days' => $this->daysOf($page->entries, $current),
            'filter' => $current,
            'filterLabels' => [
                'document' => $document === '' ? null : $this->labelOfFilteredItem($page->entries, $document, true),
                'item' => $item === '' ? null : $this->labelOfFilteredItem($page->entries, $item, false),
            ],
            'isFiltered' => !$filter->isEmpty(),
            // the history of one element or page: shown above the timeline, with the way back to all entries
            'scope' => $this->scope($page->entries, $current),
            'rail' => $this->rail($filter, $current),
            'indexUri' => $this->moduleUri([]),
            'site' => SiteDetectionResult::fromRequest($this->request->getHttpRequest())->siteNodeName->value,
            'nextUri' => $page->next === null
                ? null
                : $this->moduleUri([...$current, 'after' => $page->next->toString()]),
            'firstUri' => $after === '' ? null : $this->moduleUri($current),
        ]);
    }

    /**
     * The element or page whose history is shown (filter "item" or "document"), null for the whole log
     *
     * @param list<ActivityEntry> $entries
     * @param array<string, string> $current
     * @return array{heading: string, label: string, closeUri: string}|null
     */
    private function scope(array $entries, array $current): ?array
    {
        $name = $current['item'] !== '' ? 'item' : ($current['document'] !== '' ? 'document' : null);
        if ($name === null) {
            return null;
        }
        $label = $this->labelOfFilteredItem($entries, $current[$name], $name === 'document');
        return [
            'heading' => $this->translate('scope.' . $name) ?? $name,
            'label' => $label ?? ($this->translate('scope.unknown') ?? $current[$name]),
            'closeUri' => $this->moduleUri([...$current, 'item' => '', 'document' => '', 'after' => '']),
        ];
    }

    /**
     * The filters on the left: each option with the number of entries it would show, and its link
     *
     * @param array<string, string> $current
     * @return array<string, mixed>
     */
    private function rail(ActivityFilter $filter, array $current): array
    {
        $userCounts = $this->contentActivitySource->countsByUser($filter->withoutUser());
        $labels = [];
        foreach (array_keys($userCounts) as $userId) {
            $labels[$userId] = $this->userLabel($userId);
        }
        uasort($labels, 'strcasecmp');
        $users = [];
        foreach ($labels as $userId => $label) {
            $users[] = $this->railOption($current, 'user', $userId, $label, $userCounts[$userId], [
                'initials' => $this->initials($label),
                'isSystem' => $userId === 'system',
            ]);
        }

        $nodeTypeCounts = $this->contentActivitySource->countsByNodeType($filter->withoutItemTypes());
        $types = [];
        foreach ($this->itemTypes as $key => $configuration) {
            $count = 0;
            foreach ($configuration['nodeTypes'] ?? [] as $nodeTypeName) {
                $count += $nodeTypeCounts[$nodeTypeName] ?? 0;
            }
            $label = $this->translateLabel($configuration['label'] ?? null) ?? $key;
            $types[] = $this->railOption($current, 'type', $key, $label, $count);
        }

        $periods = [$this->railOption($current, 'period', '', $this->translate('period.all') ?? 'All', null)];
        foreach (array_keys(self::PERIODS) as $key) {
            $periods[] = $this->railOption($current, 'period', $key, $this->translate('period.' . $key) ?? $key, null);
        }
        $whats = [$this->railOption($current, 'what', '', $this->translate('what.all') ?? 'All', null)];
        foreach (array_keys(self::WHATS) as $key) {
            $whats[] = $this->railOption($current, 'what', $key, $this->translate('what.' . $key) ?? $key, null);
        }

        return [
            'allUsers' => $this->railOption(
                $current,
                'user',
                '',
                $this->translate('filter.allUsers') ?? 'All',
                array_sum($userCounts)
            ),
            'users' => $users,
            'allTypes' => $this->railOption(
                $current,
                'type',
                '',
                $this->translate('filter.allTypes') ?? 'All',
                array_sum($nodeTypeCounts)
            ),
            'types' => $types,
            'periods' => $periods,
            'whats' => $whats,
        ];
    }

    /**
     * @param array<string, string> $current
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function railOption(
        array $current,
        string $name,
        string $value,
        string $label,
        ?int $count,
        array $extra = [],
    ): array {
        return [
            'label' => $label,
            'count' => $count,
            'active' => $current[$name] === $value,
            'uri' => $this->moduleUri([...$current, $name => $value, 'after' => '']),
            ...$extra,
        ];
    }

    /**
     * The entries by day, and within a day in groups: one publish, or what one person did in live directly in the same
     * minute (e.g. the import of a site). Consecutive entries only: the log stays in its order.
     *
     * @param list<ActivityEntry> $entries
     * @param array<string, string> $filter
     * @return list<array{label: string, groups: list<array<string, mixed>>}>
     */
    private function daysOf(array $entries, array $filter): array
    {
        // one group after another: its day, its head, its user and its entries
        $groupDays = [];
        $groupHeads = [];
        $groupUsers = [];
        $groupEntries = [];
        $lastKey = null;
        foreach ($entries as $entry) {
            $day = $this->formatDay($entry->time);
            $key = $day . '/' . ($entry->group ?? 'direct:' . $entry->userId . ':' . $this->formatTime($entry->time));
            if ($lastKey !== $key) {
                $groupDays[] = $day;
                $groupUsers[] = $entry->userId;
                $groupHeads[] = [
                    'isPublish' => $entry->group !== null,
                    'time' => $this->formatClock($entry->time),
                    'userId' => $entry->userId,
                    'user' => $entry->userId === null ? null : $this->userLabel($entry->userId),
                    'initials' => $entry->userId === null ? '' : $this->initials($this->userLabel($entry->userId)),
                    'workspace' => $entry->workspaceName === null ? null : $this->workspaceLabel($entry->workspaceName),
                ];
                $groupEntries[] = [];
                $lastKey = $key;
            }
            $index = count($groupEntries) - 1;
            $view = $this->entryView($entry, $filter);
            // a publish may hold changes of others (published from a shared workspace)
            $view['otherUser'] = $entry->userId !== $groupUsers[$index] ? $view['user'] : null;
            $groupEntries[$index][] = $view;
        }

        $days = [];
        foreach ($groupHeads as $index => $head) {
            $count = count($groupEntries[$index]);
            $group = [
                ...$head,
                'entries' => $groupEntries[$index],
                'documents' => $this->documentsOf($groupEntries[$index]),
                'hidden' => max(0, $count - self::GROUP_ENTRIES_SHOWN),
                'summary' => $this->translate(
                    ($head['isPublish'] ? 'group.published.' : 'group.direct.') . ($count === 1 ? 'one' : 'many'),
                    [$count]
                ) ?? (string)$count,
            ];
            $last = count($days) - 1;
            if ($last >= 0 && $days[$last]['label'] === $groupDays[$index]) {
                $days[$last]['groups'][] = $group;
            } else {
                $days[] = ['label' => $groupDays[$index], 'groups' => [$group]];
            }
        }
        return $days;
    }

    /**
     * The changes of one publish/direct-edit group, collected by page in first-seen order. The flat entries remain on
     * the group for the detail pane; these document groups are the Workspace-like hierarchy of the timeline.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function documentsOf(array $entries): array
    {
        $documents = [];
        $indexes = [];
        foreach ($entries as $entryIndex => $entry) {
            $key = $entry['documentId'] ?? ('entry:' . $entry['key']);
            if (!isset($indexes[$key])) {
                $indexes[$key] = count($documents);
                $documents[] = [
                    'label' => $entry['pageLabel']
                        ?? ($this->translate('group.page.unknown') ?? 'Other'),
                    'folded' => $entryIndex >= self::GROUP_ENTRIES_SHOWN,
                    'entries' => [],
                ];
            }
            $documentIndex = $indexes[$key];
            $documents[$documentIndex]['entries'][] = [
                ...$entry,
                'folded' => $entryIndex >= self::GROUP_ENTRIES_SHOWN,
            ];
        }

        foreach ($documents as &$document) {
            $count = count($document['entries']);
            $document['summary'] = $this->translate(
                'group.page.' . ($count === 1 ? 'one' : 'many'),
                [$count]
            ) ?? (string)$count;
        }
        unset($document);

        return $documents;
    }

    /**
     * The base URL of the styleguide adapter's API, null when none is configured (then the fields only)
     */
    private function styleguideAdapter(): ?string
    {
        $styleguide = $this->uiFrontendConfiguration['Devclaim.NeosStyleguide'] ?? null;
        $adapter = is_array($styleguide) ? ($styleguide['adapter'] ?? null) : null;
        return is_string($adapter) && $adapter !== '' ? $adapter : null;
    }

    private function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];
        $initials = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }
        return $initials;
    }

    /**
     * @param array<string, string> $filter
     * @return array<string, mixed>
     */
    private function entryView(ActivityEntry $entry, array $filter): array
    {
        $nodeTypeManager = $this->contentRepositoryRegistry
            ->get($this->contentActivitySource->contentRepositoryId())
            ->getNodeTypeManager();
        $nodeType = $nodeTypeManager->getNodeType($entry->itemType);
        // the time of the edit only when it was well before the publish
        $initiatedAt = $entry->initiatedAt !== null
            && $entry->time->getTimestamp() - $entry->initiatedAt->getTimestamp() >= 60
            ? $entry->initiatedAt
            : null;

        // the node references of the previews (ActivityNodeReferenceResolver), of content only: a page shows its
        // fields, its content has entries of its own. Removed content: as it was (from the trash bin), outlined
        $isContent = $nodeType?->isOfType(NodeTypeNameFactory::NAME_CONTENT) === true
            && $this->styleguideAdapter() !== null;
        $isRemoval = $isContent && $this->nodeVersions->isRemoval($entry);
        $compare = match (true) {
            $isRemoval => [
                'before' => null,
                'after' => ActivityNodeReferenceResolver::reference($entry, CompareSide::After),
                'removed' => true,
            ],
            $isContent && $this->nodeVersions->isComparable($entry) => [
                'before' => $entry->action === 'created'
                    ? null
                    : ActivityNodeReferenceResolver::reference($entry, CompareSide::Before),
                'after' => ActivityNodeReferenceResolver::reference($entry, CompareSide::After),
                'removed' => false,
            ],
            default => null,
        };

        $hasCompare = $compare !== null;
        $isNew = $hasCompare && !$isRemoval && $compare['before'] === null;
        $userLabel = $entry->userId === null ? null : $this->userLabel($entry->userId);
        $edited = $initiatedAt ?? $entry->time;

        return [
            'key' => $entry->source . '-' . $entry->key,
            // what the detail pane shows: before and after, the new content, the fields, or what the action means
            'kind' => match (true) {
                $isRemoval => 'removed',
                $isNew => 'new',
                $hasCompare => 'change',
                $entry->changes !== [] => 'fields',
                default => 'state',
            },
            'note' => $this->translate('note.' . $entry->action),
            'initials' => $userLabel === null ? '' : $this->initials($userLabel),
            'edited' => $this->formatDay($edited) . ' ' . $this->formatClock($edited),
            'published' => $entry->group === null ? null : trim(
                $this->formatDay($entry->time) . ' ' . $this->formatClock($entry->time) . ' '
                . ($entry->workspaceName === null
                    ? ''
                    : ($this->translate('entry.fromWorkspace', [$this->workspaceLabel($entry->workspaceName)]) ?? ''))
            ),
            'fullTime' => $this->formatTime($entry->time),
            'initiatedAt' => $initiatedAt === null ? null : $this->formatTime($initiatedAt),
            'user' => $entry->userId === null ? null : $this->userLabel($entry->userId),
            'action' => $entry->action,
            'actionLabel' => $this->translate('action.' . $entry->action) ?? $entry->action,
            'icon' => self::ICONS[$entry->action] ?? 'fas fa-circle',
            'compare' => $hasCompare ? $compare : null,
            // new content: only how it looks, no before and after
            'isNew' => $isNew,
            'itemLabel' => $entry->itemLabel,
            'itemTypeLabel' => $nodeType === null
                ? $entry->itemType
                : ($this->translateLabel($nodeType->getLabel()) ?? $entry->itemType),
            'documentId' => $entry->documentId,
            'pageLabel' => $entry->documentLabel
                ?? ($entry->documentId === $entry->itemId ? $entry->itemLabel : null),
            'documentLabel' => $entry->documentId === $entry->itemId ? null : $entry->documentLabel,
            'changes' => array_values(array_filter(array_map(
                fn (ActivityChange $change) => $this->changeView($change, $nodeTypeManager),
                $entry->changes
            ))),
            'openUri' => $entry->documentExists && $entry->documentId !== null
                ? $this->openUri($entry->documentId, $entry->dimensionSpacePoint)
                : null,
            'filterDocumentUri' => $entry->documentId === null || $filter['document'] === $entry->documentId
                ? null
                : $this->moduleUri([...$filter, 'document' => $entry->documentId, 'item' => '']),
            // a page filtered already is its own history
            'filterItemUri' => $filter['item'] === $entry->itemId
                || ($entry->documentId === $entry->itemId && $filter['document'] === $entry->itemId)
                ? null
                : $this->moduleUri([...$filter, 'item' => $entry->itemId]),
            'filterUserUri' => $entry->userId === null || $filter['user'] === $entry->userId
                ? null
                : $this->moduleUri([...$filter, 'user' => $entry->userId]),
        ];
    }

    /**
     * @return array{label: string, old: string, new: string, oldText?: string, newText?: string}|null null: not shown
     *     (a move shows as the action); oldText/newText: texts compared word by word
     */
    private function changeView(ActivityChange $change, NodeTypeManager $nodeTypeManager): ?array
    {
        return match ($change->name) {
            '_parent' => null,
            '_nodeType' => [
                'label' => $this->translate('change.nodeType') ?? 'Type',
                'old' => $this->nodeTypeLabel($nodeTypeManager, $change->old),
                'new' => $this->nodeTypeLabel($nodeTypeManager, $change->new),
            ],
            '_nodeName', '_tag' => [
                'label' => $this->translate('change.' . substr($change->name, 1)) ?? $change->name,
                'old' => is_string($change->old) ? $change->old : '',
                'new' => is_string($change->new) ? $change->new : '',
            ],
            '_dimension' => [
                'label' => $this->translate('change.dimension') ?? 'Dimension',
                'old' => $this->dimensionLabel($change->old),
                'new' => $this->dimensionLabel($change->new),
            ],
            default => [
                'label' => $this->translateLabel($change->label) ?? $change->name,
                'old' => $this->displayValue($change->old),
                'new' => $this->displayValue($change->new),
                ...$this->texts($change),
            ],
        };
    }

    /**
     * The plain texts of a changed text property, for the word diff
     *
     * @return array{oldText?: string, newText?: string}
     */
    private function texts(ActivityChange $change): array
    {
        $text = function (mixed $value): ?string {
            if (is_array($value) && array_key_exists('value', $value)) {
                $value = $value['value'];
            }
            if ($value === null) {
                return '';
            }
            if (!is_string($value)) {
                return null;
            }
            $plain = html_entity_decode(strip_tags(str_replace(['<br', '</p>'], [' <br', ' </p>'], $value)));
            $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? '');
            return mb_substr($plain, 0, self::TEXT_LENGTH);
        };
        $old = $text($change->old);
        $new = $text($change->new);
        if ($old === null || $new === null || ($old === '' && $new === '')) {
            return [];
        }
        return ['oldText' => $old, 'newText' => $new];
    }

    /**
     * A stored value as text: serialized property values ({value, type}), reference targets, dates, plain values
     */
    private function displayValue(mixed $value): string
    {
        if (is_array($value) && array_key_exists('value', $value) && array_key_exists('type', $value)) {
            $value = $value['value'];
        }
        return match (true) {
            $value === null, $value === '' => '',
            is_bool($value) => $this->translate($value ? 'value.yes' : 'value.no') ?? ($value ? 'yes' : 'no'),
            is_int($value), is_float($value) => (string)$value,
            is_string($value) => $this->shorten($value),
            is_array($value) && array_is_list($value)
                => $this->translate('value.items', [count($value)]) ?? (string)count($value),
            is_array($value) && is_string($value['date'] ?? null) => $this->formatDateValue($value['date']),
            is_array($value) && isset($value['__identifier']) => $this->translate('value.asset') ?? 'Asset',
            default => $this->shorten(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        };
    }

    private function shorten(string $value): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5)) ?? '');
        return mb_strlen($text) > self::VALUE_LENGTH ? mb_substr($text, 0, self::VALUE_LENGTH - 1) . '…' : $text;
    }

    private function formatDateValue(string $date): string
    {
        try {
            return $this->formatTime(new \DateTimeImmutable($date));
        } catch (\Exception) {
            return $date;
        }
    }

    private function formatTime(\DateTimeImmutable $time): string
    {
        return $this->local($time)->format('d.m.Y H:i');
    }

    private function formatClock(\DateTimeImmutable $time): string
    {
        return $this->local($time)->format('H:i');
    }

    /**
     * "Heute", "Gestern" or the date
     */
    private function formatDay(\DateTimeImmutable $time): string
    {
        $day = $this->local($time)->format('Y-m-d');
        $today = $this->local(new \DateTimeImmutable());
        return match ($day) {
            $today->format('Y-m-d') => $this->translate('day.today') ?? 'Today',
            $today->sub(new \DateInterval('P1D'))->format('Y-m-d') => $this->translate('day.yesterday') ?? 'Yesterday',
            default => $this->local($time)->format('d.m.Y'),
        };
    }

    private function local(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return $time->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private function nodeTypeLabel(NodeTypeManager $nodeTypeManager, mixed $nodeTypeName): string
    {
        if (!is_string($nodeTypeName)) {
            return '';
        }
        $nodeType = $nodeTypeManager->getNodeType($nodeTypeName);
        return $nodeType === null ? $nodeTypeName : ($this->translateLabel($nodeType->getLabel()) ?? $nodeTypeName);
    }

    private function dimensionLabel(mixed $json): string
    {
        $coordinates = is_string($json) ? json_decode($json, true) : null;
        return is_array($coordinates) ? implode(', ', array_filter($coordinates, 'is_string')) : '';
    }

    /**
     * The page in the Neos backend (in the user's workspace, which Neos opens for a live address)
     */
    private function openUri(string $documentId, ?string $dimensionSpacePoint): ?string
    {
        try {
            $address = NodeAddress::create(
                $this->contentActivitySource->contentRepositoryId(),
                WorkspaceName::forLive(),
                $dimensionSpacePoint === null
                    ? DimensionSpacePoint::createWithoutDimensions()
                    : DimensionSpacePoint::fromJsonString($dimensionSpacePoint),
                NodeAggregateId::fromString($documentId),
            );
            $uriBuilder = new UriBuilder();
            $uriBuilder->setRequest($this->request->getMainRequest());
            return $uriBuilder->uriFor('index', ['node' => $address->toJson()], 'Backend', 'Neos.Neos.Ui');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, string> $arguments
     */
    private function moduleUri(array $arguments): string
    {
        return $this->uriBuilder->reset()
            ->uriFor('index', array_filter($arguments, static fn (string $value) => $value !== ''));
    }

    /**
     * The name of the page or item a filter is set to, from the entries shown (the newest has the newest name)
     *
     * @param list<ActivityEntry> $entries
     */
    private function labelOfFilteredItem(array $entries, string $id, bool $document): ?string
    {
        foreach ($entries as $entry) {
            if ($document && $entry->documentId === $id) {
                return $entry->documentId === $entry->itemId ? $entry->itemLabel : $entry->documentLabel;
            }
            if (!$document && $entry->itemId === $id) {
                return $entry->itemLabel;
            }
        }
        return null;
    }

    private function userLabel(string $userId): string
    {
        if (!isset($this->userLabels[$userId])) {
            $user = $userId === 'system' ? null : $this->userService->findUserById(UserId::fromString($userId));
            $this->userLabels[$userId] = $user?->getLabel()
                ?? ($userId === 'system' ? ($this->translate('user.system') ?? 'System') : $userId);
        }
        return $this->userLabels[$userId];
    }

    private function workspaceLabel(string $workspaceName): string
    {
        if (!isset($this->workspaceLabels[$workspaceName])) {
            try {
                $this->workspaceLabels[$workspaceName] = $this->workspaceService->getWorkspaceMetadata(
                    $this->contentActivitySource->contentRepositoryId(),
                    WorkspaceName::fromString($workspaceName)
                )->title->value;
            } catch (\Throwable) {
                // removed since
                $this->workspaceLabels[$workspaceName] = $workspaceName;
            }
        }
        return $this->workspaceLabels[$workspaceName];
    }

    /**
     * A label that may be a translation id ("Vendor.Package:Source:id")
     */
    private function translateLabel(?string $label): ?string
    {
        if ($label === null || $label === '') {
            return null;
        }
        if (preg_match('/^([A-Za-z0-9.]+):([A-Za-z0-9.\/]+):(.+)$/', $label, $matches) !== 1) {
            return $label;
        }
        // the source of a node type label is a path: "NodeTypes.Shortcut" is NodeTypes/Shortcut.xlf
        $source = str_replace('.', '/', $matches[2]);
        $translated = $this->translator->translateById($matches[3], [], null, null, $source, $matches[1]);
        // not translated: null, the caller falls back to the name (a property, a node type)
        return is_string($translated) && $translated !== '' ? $translated : null;
    }

    /**
     * @param list<mixed> $arguments
     */
    private function translate(string $id, array $arguments = []): ?string
    {
        $translated = $this->translator->translateById($id, $arguments, null, null, 'Main', self::PACKAGE);
        return is_string($translated) && $translated !== '' ? $translated : null;
    }
}
