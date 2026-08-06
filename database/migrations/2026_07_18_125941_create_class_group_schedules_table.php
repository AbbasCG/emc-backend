<?php

use App\Models\ClassGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('class_group_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->string('day_of_week', 20);
            $table->time('start_time');
            $table->time('end_time');
            $table->string('delivery_mode', 20)->default('online');
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['class_group_id', 'day_of_week', 'start_time'], 'cgs_group_day_time_unique');
        });

        // Backfill: one class_groups row's schedule_day/schedule_time becomes
        // one class_group_schedules row, so existing classes keep their
        // weekly schedule after the multi-day model replaces the old columns.
        ClassGroup::whereNotNull('schedule_day')
            ->whereNotNull('schedule_time')
            ->get()
            ->each(function (ClassGroup $group) {
                $start = $group->schedule_time;
                $end   = $start ? substr($start, 0, 5) : null;

                \DB::table('class_group_schedules')->insert([
                    'class_group_id' => $group->id,
                    'day_of_week'    => $group->schedule_day,
                    'start_time'     => $group->schedule_time,
                    // No stored end time historically — default to a 90-minute
                    // slot so migrated rows are still a valid, editable range.
                    'end_time'       => $end
                        ? \Carbon\Carbon::createFromFormat('H:i', $end)->addMinutes(90)->format('H:i:s')
                        : '00:00:00',
                    'delivery_mode'  => $group->location_type ?? 'online',
                    'location'       => $group->location_type === 'in_person' ? null : null,
                    'is_active'      => true,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_group_schedules');
    }
};
