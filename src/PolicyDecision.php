<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final class PolicyDecision
{
    /** @param list<string> $violations @param array<string, float> $scores */
    public function __construct(
        public readonly bool $approved,
        public readonly array $violations,
        public readonly array $scores,
    ) {
    }
}
