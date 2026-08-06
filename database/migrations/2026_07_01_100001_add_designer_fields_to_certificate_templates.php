<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            // Designer mode: true = use Blade file, false = use html_template column
            $table->boolean('designer_mode')->default(false)->after('is_active');
            $table->string('blade_path')->nullable()->default('certificates.templates.default')->after('designer_mode');

            // Learning path / camp label
            $table->string('learning_path_name_ar')->nullable()->after('blade_path');
            $table->string('learning_path_name_en')->nullable()->after('learning_path_name_ar');

            // Organization branding
            $table->string('org_name_en')->nullable()->default('Educational Mastar Central')->after('learning_path_name_en');
            $table->string('org_name_ar')->nullable()->default('مركز ماستر التعليمي')->after('org_name_en');
            $table->string('org2_name_en')->nullable()->after('org_name_ar');
            $table->string('org2_name_ar')->nullable()->after('org2_name_en');
            $table->string('logo_primary')->nullable()->after('org2_name_ar');
            $table->string('logo_secondary')->nullable()->after('logo_primary');

            // Typography
            $table->string('font_arabic')->nullable()->default('Cairo')->after('logo_secondary');
            $table->string('font_english')->nullable()->default('Poppins')->after('font_arabic');

            // Colors
            $table->string('color_primary')->nullable()->default('#0D47A1')->after('font_english');
            $table->string('color_secondary')->nullable()->default('#F57C00')->after('color_primary');
            $table->string('color_accent')->nullable()->default('#29B6F6')->after('color_secondary');
            $table->string('color_text')->nullable()->default('#1A1A1A')->after('color_accent');

            // Feature toggles
            $table->boolean('show_admin_signature')->default(true)->after('color_text');
            $table->boolean('show_program_signature')->default(true)->after('show_admin_signature');
            $table->boolean('show_official_stamp')->default(true)->after('show_program_signature');

            // Administrative signature
            $table->string('admin_sig_image')->nullable()->after('show_official_stamp');
            $table->string('admin_sig_name_en')->nullable()->default('Tareq Tuaayman')->after('admin_sig_image');
            $table->string('admin_sig_name_ar')->nullable()->default('طارق طعيمان')->after('admin_sig_name_en');
            $table->string('admin_sig_title_en')->nullable()->default('Founder & General Manager – EMC')->after('admin_sig_name_ar');
            $table->string('admin_sig_title_ar')->nullable()->default('مؤسس ومدير عام – EMC')->after('admin_sig_title_en');

            // Programs department signature
            $table->string('program_sig_image')->nullable()->after('admin_sig_title_ar');
            $table->string('program_sig_name_en')->nullable()->after('program_sig_image');
            $table->string('program_sig_name_ar')->nullable()->default('إدارة المعسكر الصيفي للذكاء الاصطناعي')->after('program_sig_name_en');
            $table->string('program_sig_title_en')->nullable()->after('program_sig_name_ar');
            $table->string('program_sig_title_ar')->nullable()->default('مركز ماستر التعليمي – EMC')->after('program_sig_title_en');

            // Official stamp
            $table->string('official_stamp_image')->nullable()->after('program_sig_title_ar');

            // Layout & print
            $table->string('paper_size')->nullable()->default('A4')->after('official_stamp_image');
            $table->string('orientation')->nullable()->default('landscape')->after('paper_size');
            $table->string('pdf_quality')->nullable()->default('high')->after('orientation');
            $table->integer('margin_top')->nullable()->default(8)->after('pdf_quality');
            $table->integer('margin_bottom')->nullable()->default(8)->after('margin_top');
            $table->integer('margin_left')->nullable()->default(12)->after('margin_bottom');
            $table->integer('margin_right')->nullable()->default(12)->after('margin_left');
            $table->string('bg_style')->nullable()->default('default')->after('margin_right');
            $table->string('border_style')->nullable()->default('default')->after('bg_style');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->dropColumn([
                'designer_mode', 'blade_path',
                'learning_path_name_ar', 'learning_path_name_en',
                'org_name_en', 'org_name_ar', 'org2_name_en', 'org2_name_ar',
                'logo_primary', 'logo_secondary',
                'font_arabic', 'font_english',
                'color_primary', 'color_secondary', 'color_accent', 'color_text',
                'show_admin_signature', 'show_program_signature', 'show_official_stamp',
                'admin_sig_image', 'admin_sig_name_en', 'admin_sig_name_ar',
                'admin_sig_title_en', 'admin_sig_title_ar',
                'program_sig_image', 'program_sig_name_en', 'program_sig_name_ar',
                'program_sig_title_en', 'program_sig_title_ar',
                'official_stamp_image',
                'paper_size', 'orientation', 'pdf_quality',
                'margin_top', 'margin_bottom', 'margin_left', 'margin_right',
                'bg_style', 'border_style',
            ]);
        });
    }
};
