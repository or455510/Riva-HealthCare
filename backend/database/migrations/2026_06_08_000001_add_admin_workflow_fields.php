<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('active')->after('is_active');
            }
        });

        Schema::table('doctors', function (Blueprint $table) {
            if (! Schema::hasColumn('doctors', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('verification_status');
            }

            if (! Schema::hasColumn('doctors', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('verified_at');
            }
        });

        Schema::table('emergency_alerts', function (Blueprint $table) {
            if (! Schema::hasColumn('emergency_alerts', 'status')) {
                $table->string('status')->default('active')->after('severity');
            }
        });

        Schema::table('contact_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('contact_messages', 'status')) {
                $table->string('status')->default('new')->after('message');
            }

            if (! Schema::hasColumn('contact_messages', 'handled_at')) {
                $table->timestamp('handled_at')->nullable()->after('status');
            }
        });

        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('notifications', 'email_status')) {
                $table->string('email_status')->default('not_sent')->after('category');
            }

            if (! Schema::hasColumn('notifications', 'whatsapp_status')) {
                $table->string('whatsapp_status')->default('not_sent')->after('email_status');
            }
        });
    }

    public function down(): void
    {
        $drops = [
            'notifications' => ['whatsapp_status', 'email_status'],
            'contact_messages' => ['handled_at', 'status'],
            'emergency_alerts' => ['status'],
            'doctors' => ['rejection_reason', 'verified_at'],
            'users' => ['status'],
        ];

        foreach ($drops as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }
};
