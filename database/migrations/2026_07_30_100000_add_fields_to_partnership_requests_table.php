<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partnership_requests', function (Blueprint $table) {
            // Required going forward (enforced by StorePartnershipRequestRequest),
            // kept nullable at the DB level so this migration never fails against
            // any pre-existing rows.
            $table->string('country', 100)->nullable()->after('phone');
            $table->string('city', 100)->nullable()->after('country');
            $table->string('website', 255)->nullable()->after('city');

            // Distinct from `type` (organization type) — what kind of
            // partnership is being proposed. Same "plain string, validated
            // via an `in:` rule" pattern already used for `type`/`status`.
            $table->string('partnership_type', 50)->nullable()->after('type');

            // Free-text detail captured when `type` / `partnership_type` is 'other'.
            $table->string('type_other', 255)->nullable()->after('partnership_type');
            $table->string('partnership_type_other', 255)->nullable()->after('type_other');

            $table->boolean('privacy_accepted')->default(false)->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('partnership_requests', function (Blueprint $table) {
            $table->dropColumn([
                'country', 'city', 'website',
                'partnership_type', 'type_other', 'partnership_type_other',
                'privacy_accepted',
            ]);
        });
    }
};
