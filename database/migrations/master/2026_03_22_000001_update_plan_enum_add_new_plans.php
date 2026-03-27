<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Expand the plan enum on tenants table to include new plan types
        DB::statement("ALTER TABLE tenants MODIFY COLUMN plan ENUM('free','starter','pro','pro_ai','business','enterprise') NOT NULL DEFAULT 'free'");

        // Expand the plan enum on subscriptions table
        DB::statement("ALTER TABLE subscriptions MODIFY COLUMN plan ENUM('free','starter','pro','pro_ai','business','enterprise') NOT NULL DEFAULT 'free'");

        // Update pro plan limits for existing pro tenants (old pro was 500 products / 5 users, new pro is 2000 / 10)
        DB::table('tenants')
            ->where('plan', 'pro')
            ->update([
                'product_limit' => 2000,
                'user_limit' => 10,
            ]);
    }

    public function down(): void
    {
        // Revert any pro_ai or enterprise tenants back to pro before shrinking enum
        DB::table('tenants')
            ->whereIn('plan', ['pro_ai', 'enterprise'])
            ->update(['plan' => 'pro']);

        DB::table('subscriptions')
            ->whereIn('plan', ['pro_ai', 'enterprise'])
            ->update(['plan' => 'pro']);

        DB::statement("ALTER TABLE tenants MODIFY COLUMN plan ENUM('free','starter','pro','business') NOT NULL DEFAULT 'free'");
        DB::statement("ALTER TABLE subscriptions MODIFY COLUMN plan ENUM('free','starter','pro','business') NOT NULL DEFAULT 'free'");
    }
};
