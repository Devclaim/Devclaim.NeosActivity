# Devclaim.NeosActivity

An activity log of the Neos backend, like the activity log of Directus: who did what, when, to which item, with the
old and the new values. One list for several sources, merged when it is shown; nothing is stored twice.

- **Content** (this package): a projection of the event-sourced content repository (Neos 9), one row per change that
    reached the live workspace, with the editor, the time of the edit and of the publish, the workspace it came from,
    the page it is on and the changed values. Like the tables of Neos it is only written by the projection and can be
    dropped and replayed from the events at any time.
- **Other sources** implement `ActivitySourceInterface` and are registered in the settings (for example the moderation
    cases of `Devclaim.NeosModeration`, read from their own history).

Read only for now; undo comes next (Planning in the project using it).

## The module

Management → **Activity** (`/neos/management/activity`), administrators only (`Policy.yaml`): the log shows who edited
what and when, personal data of the editors. Three panes on wide screens:

- **Filters**, each with the number of entries: search (the name of the item or its page), period, user, kind of
    item (`itemTypes`), kind of change; page and element come from the links "History" of an entry.
- **Timeline**: by day, a card per publish (or per person and minute for changes made in live directly). Within each
    card the changes are grouped by page, like the Workspace review, and the changed elements are nested below their
    page. Big cards are folded after five entries. Arrow keys move through them.
- **The selected entry**: what, who, when edited and published, links to its history and to the page in Neos, and
    the change (see the compare below): deleted content as it was, or what the action means (hidden, moved).

## The compare

An entry opens to its change. Changed properties of a content node are shown as the website shows them: the node
before and after, rendered by the site's own frontend in two frames (side by side, or one over the other with a
slider), the changed words marked in both. The fields view lists every changed property, old and new with the changed
words marked; it is the only view when the frontend cannot render the node.

`NodeVersions` builds the node of each side: the node as it is live now, with the changed properties set to their old
or new values (the other properties and the child nodes as they are now). The frontend renders it through the
**styleguide adapter** of the site (`devclaim/neos-styleguide`, required): its manifest names a page for node previews
(`nodePreview`, PROTOCOL.md of the styleguide), the module loads it with the node reference
`activity:<sequence number>:before|after`, which `ActivityNodeReferenceResolver` resolves (only for who may see the
log). The page renders the node with the components and styles of the website, inside `data-styleguide-root`; the
module sizes its frame to that element.

Deleted content is shown as it was, from the trash bin of Neos (until it is emptied), outlined in red.

The frames, the views and the word diff are the compare of `Devclaim.NeosModernBackend` (required), shared with the
review of the workspace module. An adapter without `nodePreview` (or no adapter configured): the module shows the
changed fields only.

## How the content log is built

`ActivityProjection` (registered as `Devclaim.NeosActivity:Activity` in the content repository preset) keeps three
tables, `cr_<id>_p_devclaim_activity_*`:

- `_entries`: the log.
- `_nodes`: the live state of the nodes as far as the log needs it (type, parent, page, properties, references,
    removed). A property change only holds the new values; the old ones and the page come from here. Removed nodes stay,
    marked.
- `_drafts`: the changes in other workspaces. Publishing copies events to live with the original user and edit time, so
    a live change is matched to its draft by node, event type, user and edit time, which gives the workspace it was
    published from; `WorkspaceWasPublished` groups the entries of one publish.

Deleting in Neos is a soft removal first (an entry "Deleted"); the later hard removal by the system is not shown again.
Tethered nodes are part of the creation of their parent.

The projection never throws: a failure is logged (system log) and the event skipped, so the log cannot keep editors
from working. Rebuild it with `./flow subscription:replay Devclaim.NeosActivity:Activity --force`.

## Settings

```yaml
Devclaim:
    NeosActivity:
        contentRepository: default
        sources:            # key: {className, position}
            content: …
        itemTypes:          # the filter "Kind": key: {label, nodeTypes}
            events:
                label: 'Events'
                nodeTypes: ['Acme.Site:Content.Event']
        pageSize: 50

Neos:
    ContentRepositoryRegistry:
        presets:
            default:
                projections:
                    'Devclaim.NeosActivity:Activity':
                        options:
                            workspaceName: live
                            labelProperties: ['title', 'label', 'name', 'headline', 'text']   # the name of a node in the log
```

## Installation

```bash
composer require devclaim/neos-activity
./flow cr:setup                      # creates the tables
./flow subscription:replay Devclaim.NeosActivity:Activity --force   # fills them from the existing history
```
