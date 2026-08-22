<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // صمام أمان: config مخبّأ (php artisan config:cache) يتجاوز إعدادات
        // phpunit.xml فتضرب الاختبارات قاعدة التطوير الحقيقية وكاش الملفات
        // المشترك. نوقف الحزمة فوراً بدل أن تمسح البيانات المحلية.
        if (! $this->app->environment('testing')
            || config('database.connections.sqlite.database') !== ':memory:') {
            self::fail(
                'Cached config detected (env='.$this->app->environment()
                .') — run `php artisan config:clear` before testing, '
                .'otherwise the suite runs against the real dev database and wipes it.'
            );
        }
    }
}
