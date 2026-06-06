<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable()->unique()->after('profile_image');
            }
            if (!Schema::hasColumn('users', 'google_avatar')) {
                $table->string('google_avatar')->nullable()->after('google_id');
            }
            if (!Schema::hasColumn('users', 'profile_completed_at')) {
                $table->timestamp('profile_completed_at')->nullable()->after('email_verified_at');
            }
        });

        Schema::table('patients', function (Blueprint $table) {
            if (!Schema::hasColumn('patients', 'about')) {
                $table->text('about')->nullable();
            }
        });

        Schema::table('caregivers', function (Blueprint $table) {
            if (!Schema::hasColumn('caregivers', 'bio')) {
                $table->text('bio')->nullable();
            }
            if (!Schema::hasColumn('caregivers', 'is_available')) {
                $table->boolean('is_available')->default(true);
            }
        });

        Schema::table('doctors', function (Blueprint $table) {
            if (!Schema::hasColumn('doctors', 'is_verified')) {
                $table->boolean('is_verified')->default(false);
            }
        });
    }

    public function down(): void
    {
        //
    }
};
