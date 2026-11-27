<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * SECURITY (Phase 0): replacement for the removed public-registration admin path.
 *
 * Prior to this fix, POST /api/auth/register accepted role=admin from any
 * unauthenticated caller, which meant anyone on the internet could create a
 * platform administrator account. That validation rule has been removed
 * (see AuthController::register()). This command is the intended replacement:
 * admin accounts are now created only by someone with shell/deploy access to
 * the server, never over the public API.
 *
 * Usage:
 *   php artisan riva:create-admin
 *   php artisan riva:create-admin --email=admin@example.com --name="Riva Admin"
 */
class CreateAdminUser extends Command
{
    protected $signature = 'riva:create-admin
                            {--email= : Admin email address}
                            {--name= : Admin full name}
                            {--password= : Admin password (prompted securely if omitted)}';

    protected $description = 'Create a platform administrator account (not available over the public API).';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Admin email');
        $name = $this->option('name') ?: $this->ask('Admin full name');
        $password = $this->option('password') ?: $this->secret('Admin password (min 8 characters)');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $nameParts = explode(' ', trim($name), 2);

        $user = User::create([
            'first_name' => $nameParts[0],
            'last_name' => $nameParts[1] ?? '',
            'name' => $name,
            'email' => $email,
            'password' => $password, // hashed automatically by the User::password cast
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
            'profile_completed_at' => now(),
        ]);

        $this->info("Admin user created: {$user->email} (id={$user->id})");

        return self::SUCCESS;
    }
}
