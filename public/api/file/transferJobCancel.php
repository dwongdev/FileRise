<?php

// public/api/file/transferJobCancel.php

declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';

$controller = new \FileRise\Http\Controllers\FileController();
$controller->transferJobCancel();
