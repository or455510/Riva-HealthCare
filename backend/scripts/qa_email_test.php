<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email = config('mail.from.address');
$user = App\Models\User::firstOrCreate(
    ['email' => $email],
    [
        'first_name' => 'QA',
        'last_name' => 'Mail',
        'name' => 'QA Mail',
        'password' => 'password',
        'role' => 'admin',
        'is_active' => true,
    ]
);

app(App\Services\NotificationService::class)->create(
    $user,
    'emergency_alert',
    'QA Emergency Alert',
    'SMTP delivery verification from Riva QA.',
    ['source' => 'qa_email_test', 'severity' => 'info'],
    '/notifications',
    'emergency'
);

echo "sent\n";
