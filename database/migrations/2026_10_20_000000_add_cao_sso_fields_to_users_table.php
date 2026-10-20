<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The table that stores authenticatable users.
     */
    public function usersTable(): string
    {
        return config('cao-sso.users_table')
            ?: config('auth.providers.users.table')
            ?: 'users';
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $table = $this->usersTable();
        $columns = config('cao-sso.columns');

        $missing = array_filter(
            [$columns['cao_user_id'], $columns['cao_client_id']],
            fn ($column) => ! Schema::hasColumn($table, $column)
        );

        if (empty($missing)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $missing) {
            if (in_array($columns['cao_user_id'], $missing, true)) {
                $blueprint->string($columns['cao_user_id'])->nullable()
                    ->comment('CAO SSO user identifier (sub claim)');
            }

            if (in_array($columns['cao_client_id'], $missing, true)) {
                $blueprint->string($columns['cao_client_id'])->nullable()
                    ->comment('CAO SSO client_id this user authenticated through');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $columns = config('cao-sso.columns');

        Schema::table($this->usersTable(), function (Blueprint $table) use ($columns) {
            $table->dropColumn([$columns['cao_user_id'], $columns['cao_client_id']]);
        });
    }
};
