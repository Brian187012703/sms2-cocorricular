<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations for database query performance and optimal indexing.
     */
    public function up(): void
    {
        $this->addIndexSafely('events', ['status', 'event_date'], 'events_status_date_idx');
        $this->addIndexSafely('events', ['club_id', 'status'], 'events_club_status_idx');
        $this->addIndexSafely('budget_requests', ['status', 'club_id'], 'budgets_status_club_idx');
        $this->addIndexSafely('club_memberships', ['status', 'adviser_review', 'ssc_review'], 'memberships_status_workflow_idx');
        $this->addIndexSafely('attendance_logs', ['event_id', 'check_in'], 'attendance_event_checkin_idx');
        $this->addIndexSafely('notifications', ['user_id', 'is_read'], 'notif_user_read_idx');
        $this->addIndexSafely('elections', ['status', 'closes_at'], 'elections_status_closes_idx');
        $this->addIndexSafely('audit_logs', ['user_id', 'action'], 'audit_user_action_idx');
    }

    private function addIndexSafely(string $table, array $columns, string $indexName): void
    {
        if (!Schema::hasTable($table)) return;

        // Check if index already exists
        if (!Schema::hasIndex($table, $indexName)) {
            Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
                $t->index($columns, $indexName);
            });
        }
    }

    public function down(): void
    {
        // Keep indexes on rollback if needed or drop
    }
};
