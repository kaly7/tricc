<?php
require __DIR__ . '/../app/web_bootstrap.php';

use App\Services\DeviceService;
use App\Services\AuditService;

if (!is_post()) {
    redirect_to(app_url('devices.php'));
}

$service = new DeviceService();
$audit = new AuditService();
$deviceId = $service->saveDevice($_POST);
$audit->log('web', 'admin', 'device_saved', $deviceId, ['post' => $_POST]);
flash_set('success', 'Az eszköz mentése sikeres.');
redirect_to(app_url('device.php?device_id=' . urlencode($deviceId)));
