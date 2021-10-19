<?php

/**
 * (C) 2019 i.antushevich
 * Date: 18.04.19
 * Time: 16:29
 */

namespace Np\DBTools\Services\Sync;

interface SyncerInterface
{
    public function sync(int $id, array $entity, array $syncSchema);

    public function unsync(int $id, array $syncSchema);
}
