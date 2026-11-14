<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Staff substituted a line, or undid a substitution. */
class OrderSubstitutionChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const SUBSTITUTED = 'substituted';

    public const UNDONE = 'substitution_undone';

    public function __construct(public readonly int $substitutionId, public readonly string $kind) {}
}
