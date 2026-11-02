<?php

declare(strict_types=1);

namespace Devclaim\NeosActivity\Compare;

/**
 * The two sides of a compared change: the node before and after it
 */
enum CompareSide: string
{
    case Before = 'before';
    case After = 'after';
}
