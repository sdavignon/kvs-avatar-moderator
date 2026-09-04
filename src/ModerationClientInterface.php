<?php

declare(strict_types=1);

namespace KvsAvatarModerator;

interface ModerationClientInterface
{
    /** @return array{id?: string, model?: string, result: array<string, mixed>} */
    public function moderate(string $dataUrl): array;
}
