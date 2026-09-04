<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

final class PolicyEngine
{
    /** @param list<string> $blockedCategories */
    public function __construct(
        private readonly bool $blockOnModelFlagged,
        private readonly array $blockedCategories,
    ) {
    }

    /** @param array<string, mixed> $result */
    public function decide(array $result): PolicyDecision
    {
        $categories = is_array($result['categories'] ?? null) ? $result['categories'] : [];
        $rawScores = is_array($result['category_scores'] ?? null) ? $result['category_scores'] : [];
        $blocked = array_fill_keys($this->blockedCategories, true);
        $violations = [];

        foreach ($categories as $category => $flagged) {
            if ($flagged === true && (isset($blocked[$category]) || isset($blocked['*']))) {
                $violations[] = (string) $category;
            }
        }

        if ($this->blockOnModelFlagged && ($result['flagged'] ?? false) === true && $violations === []) {
            $violations[] = 'model_flagged';
        }

        $scores = [];
        foreach ($rawScores as $category => $score) {
            if (is_int($score) || is_float($score)) {
                $scores[(string) $category] = round((float) $score, 6);
            }
        }

        sort($violations);
        return new PolicyDecision($violations === [], $violations, $scores);
    }
}
