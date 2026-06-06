<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$notification = App\Models\Notification::latest()->first();
echo json_encode([
    'id' => $notification?->id,
    'notifiable_id' => $notification?->notifiable_id,
    'type' => $notification?->type,
    'title' => $notification?->title,
    'category' => $notification?->category,
    'read_at' => $notification?->read_at,
], JSON_PRETTY_PRINT) . PHP_EOL;
