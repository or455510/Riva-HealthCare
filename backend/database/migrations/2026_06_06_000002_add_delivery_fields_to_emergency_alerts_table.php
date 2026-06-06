<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('emergency_alerts')) {
            return;
        }

        Schema::table('emergency_alerts', function (Blueprint $table) {
            if (!Schema::hasColumn('emergency_alerts', 'pain_level')) {
                $table->unsignedTinyInteger('pain_level')->nullable()->after('message');
            }

            if (!Schema::hasColumn('emergency_alerts', 'symptoms')) {
                $table->text('symptoms')->nullable()->after('pain_level');
            }

            if (!Schema::hasColumn('emergency_alerts', 'admin_email_sent_at')) {
                $table->timestamp('admin_email_sent_at')->nullable()->after('resolved_at');
            }

            if (!Schema::hasColumn('emergency_alerts', 'whatsapp_sent_at')) {
                $table->timestamp('whatsapp_sent_at')->nullable()->after('admin_email_sent_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('emergency_alerts')) {
            return;
        }

        Schema::table('emergency_alerts', function (Blueprint $table) {
            foreach (['whatsapp_sent_at', 'admin_email_sent_at', 'symptoms', 'pain_level'] as $column) {
                if (Schema::hasColumn('emergency_alerts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
