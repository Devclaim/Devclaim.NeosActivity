<?php

declare(strict_types=1);

namespace Devclaim\NeosActivity\Domain;

/**
 * A changed value of an entry: a property, a reference, the parent, the node type. The values are as the source stores
 * them (for content: the serialized property values), the module only displays them.
 */
final readonly class ActivityChange
{
    public function __construct(
        public string $name,
        /** the label of the name, may be a translation id ("Vendor.Package:Source:id") */
        public ?string $label,
        public mixed $old,
        public mixed $new,
    ) {
    }
}
