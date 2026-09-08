<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * A cache store that counts how often the application asks it to drop its expired entries.
 */
class PruneCountingAdapter extends FilesystemAdapter
{
    public int $pruneCalls = 0;

    public function prune(): bool
    {
        $this->pruneCalls++;

        return true;
    }
}
