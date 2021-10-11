<?php

/**
 * (C) 2019 ООО "Напоправку.ру"
 * Date: 18.04.19
 * Time: 16:29
 */

namespace Napopravku\SuperDB\Services\Sync;

interface SyncerInterface
{
    public function sync(int $id, array $entity, array $syncSchema);

    public function unsync(int $id, array $syncSchema);
}
