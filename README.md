Database Synchronizer
=========================
Выполняет однонаправленную синхронизацию Bixtrix'овых IBlockElement, IBlockSection и FlatTable в обычные таблицы согласно конфигу `database/sync.json`

```php
<?php

use Np\DBTools\Services\Sync\SyncService;

$syncService = new SyncService();

if ($syncService->checkImplementation($type, $subType)) {
    $syncService->sync($type, $subType, $id, $entity);
}
```  
