<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final readonly class PolicyDecision
{
    /** @param list<string> $violations @param array<string, float> $scores */
    public function __construct(
        public bool $approved,
        public array $violations,
        public array $scores,
    ) {
    }
}
