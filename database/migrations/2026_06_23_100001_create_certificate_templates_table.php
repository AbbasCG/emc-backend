<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('custom');
            // type: course_completion, workshop_attendance, summer_camp, learning_track,
            //       partner, guest_speaker, volunteer, internship, sponsor, custom
            $table->string('language')->default('arabic');
            // language: arabic, english, bilingual
            $table->string('background_path')->nullable();
            $table->longText('html_template');
            $table->text('css_template')->nullable();
            $table->json('config_json')->nullable();
            $table->string('code_prefix')->default('EMC-CR');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_templates');
    }
};
