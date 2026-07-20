<?php

namespace Database\Factories;

use App\Models\CertificateTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificateTemplate>
 */
class CertificateTemplateFactory extends Factory
{
    protected $model = CertificateTemplate::class;

    public function definition(): array
    {
        return [
            'name'            => fake()->words(2, true) . ' Template',
            'type'            => 'course_completion',
            'language'        => 'arabic',
            'html_template'   => '<html><body>{{student_name}} — {{course_name}}</body></html>',
            'css_template'    => null,
            'config_json'     => null,
            'code_prefix'     => 'EMC-CR',
            'is_active'       => true,
            'is_default'      => false,
            'designer_mode'   => false,
        ];
    }
}
