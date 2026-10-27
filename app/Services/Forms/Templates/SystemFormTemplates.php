<?php

namespace App\Services\Forms\Templates;

/**
 * The built-in template library of Smart Forms (Phase 6). Defined in code, never stored: a deployment can improve a template
 * without touching data, and a form created from one is an independent copy (changing a template never changes a form).
 *
 * Every definition uses the builder's own payload format: blocks with a negative `ref`, rules that name blocks by those refs. The
 * test suite runs each one through the same normalization and publication checks a real form goes through.
 *
 * Several templates overlap a workflow the platform already runs (volunteer, ambassador and consultant applications, partnership
 * requests, HR requests, expenses, support tickets, course enrollment). Those workflows carry business logic a form does not
 * (statuses, payments, accounts, approvals), so a template never replaces them: it carries a `domain_notice` that the library shows,
 * pointing to the module that does the real work. The template is for the cases around it (a quick survey, an internal round, an event).
 */
final class SystemFormTemplates
{
    public const PREFIX = 'system:';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /** @return array<string, array<string, mixed>> keyed by template key */
    public static function all(): array
    {
        return self::$cache ??= self::build();
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    private static function build(): array
    {
        $templates = [
            self::courseRegistration(),
            self::courseEvaluation(),
            self::instructorEvaluation(),
            self::studentFeedback(),
            self::volunteerApplication(),
            self::ambassadorApplication(),
            self::consultantApplication(),
            self::partnershipRequest(),
            self::eventRegistration(),
            self::attendanceConfirmation(),
            self::internalHrRequest(),
            self::leaveRequest(),
            self::reimbursementRequest(),
            self::generalSurvey(),
            self::complaint(),
            self::suggestion(),
            self::contactRequest(),
            self::meetingFeedback(),
        ];

        $out = [];
        foreach ($templates as $t) {
            $out[$t['key']] = $t;
        }

        return $out;
    }

    // ── Building blocks ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $extra */
    private static function q(int $ref, string $type, string $label, array $extra = []): array
    {
        // `$extra` first: its settings (required, options, config, logic) win over the defaults.
        return $extra + ['ref' => -$ref, 'type' => $type, 'label' => $label, 'required' => false];
    }

    private static function required(array $block): array
    {
        return array_replace($block, ['required' => true]);
    }

    /** @param  list<string>  $options */
    private static function choice(int $ref, string $type, string $label, array $options, bool $required = true): array
    {
        return self::q($ref, $type, $label, ['options' => $options, 'required' => $required]);
    }

    private static function stars(int $ref, string $label, bool $required = true): array
    {
        return self::q($ref, 'star_rating', $label, ['required' => $required, 'config' => ['scale' => ['max' => 5]]]);
    }

    private static function scale(int $ref, string $label, string $minLabel, string $maxLabel): array
    {
        return self::q($ref, 'linear_scale', $label, ['required' => true, 'config' => ['scale' => ['min' => 1, 'max' => 10, 'min_label' => $minLabel, 'max_label' => $maxLabel]]]);
    }

    /** A rule that shows a block when another (earlier) block has a value. */
    private static function showWhen(int $sourceRef, string $operator, mixed $value = null): array
    {
        $condition = ['question' => -$sourceRef, 'operator' => $operator];
        if ($value !== null) {
            $condition['value'] = $value;
        }

        return ['logic' => ['action' => 'show', 'match' => 'all', 'conditions' => [$condition]]];
    }

    /** The contact block most templates start with. @return list<array<string, mixed>> */
    private static function contact(int $from, bool $phone = true): array
    {
        $blocks = [
            self::required(self::q($from, 'text', 'الاسم الكامل')),
            self::required(self::q($from + 1, 'email', 'البريد الإلكتروني')),
        ];
        if ($phone) {
            $blocks[] = self::q($from + 2, 'phone', 'رقم الهاتف');
        }

        return $blocks;
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    private static function template(string $key, string $name, string $category, ?string $formType, string $description, array $questions, ?string $notice = null, bool $reviewStep = false): array
    {
        return [
            'key'           => $key,
            'name'          => $name,
            'category'      => $category,
            'form_type'     => $formType,
            'description'   => $description,
            'domain_notice' => $notice,
            'definition'    => [
                'title'       => $name,
                'description' => $description,
                'type'        => $formType ?? 'general',
                'flow'        => $reviewStep ? ['review_step' => true] : null,
                'questions'   => $questions,
            ],
        ];
    }

    // ── The templates ────────────────────────────────────────────────────────

    private static function courseRegistration(): array
    {
        return self::template('course_registration', 'تسجيل اهتمام بدورة', 'education', 'student_register',
            'نموذج لجمع طلبات الاهتمام بدورة قادمة أو بقائمة انتظار.',
            [
                ...self::contact(1),
                self::required(self::q(4, 'text', 'اسم الدورة المطلوبة')),
                self::choice(5, 'radio', 'المستوى الحالي', ['مبتدئ', 'متوسط', 'متقدم']),
                self::choice(6, 'checkbox', 'الأوقات المناسبة لك', ['صباحاً', 'بعد الظهر', 'مساءً', 'عطلة نهاية الأسبوع'], false),
                self::q(7, 'textarea', 'ما الذي تتوقعه من الدورة؟'),
                self::required(self::q(8, 'consent', 'أوافق على أن تتواصل معي المنصة بخصوص هذه الدورة')),
            ],
            'التسجيل الفعلي في الدورات والدفع وقوائم الانتظار تتم من خلال وحدة الدورات. استخدم هذا القالب لجمع الاهتمام قبل فتح التسجيل فقط.',
        );
    }

    private static function courseEvaluation(): array
    {
        return self::template('course_evaluation', 'تقييم دورة', 'feedback', 'course_eval',
            'تقييم المتعلمين للدورة بعد انتهائها: المحتوى والتنظيم والفائدة.',
            [
                self::required(self::q(1, 'text', 'اسم الدورة')),
                self::stars(2, 'تقييمك العام للدورة'),
                self::stars(3, 'وضوح المحتوى'),
                self::stars(4, 'تنظيم الدورة والالتزام بالمواعيد'),
                self::scale(5, 'ما مدى احتمال أن توصي بهذه الدورة لغيرك؟', 'غير محتمل', 'محتمل جداً'),
                self::choice(6, 'yes_no', 'هل حققت الدورة توقعاتك؟', []),
                self::q(7, 'textarea', 'ما الذي لم يحقق توقعاتك؟', self::showWhen(6, 'equals', 'no')),
                self::q(8, 'textarea', 'اقتراحات لتحسين الدورة'),
            ],
        );
    }

    private static function instructorEvaluation(): array
    {
        return self::template('instructor_evaluation', 'تقييم مدرّب', 'feedback', 'trainer_eval',
            'تقييم أداء المدرّب في الشرح والتفاعل وإدارة الوقت.',
            [
                self::required(self::q(1, 'text', 'اسم المدرّب')),
                self::q(2, 'text', 'اسم الدورة أو الورشة'),
                self::stars(3, 'وضوح الشرح'),
                self::stars(4, 'التفاعل مع المتعلمين والإجابة عن الأسئلة'),
                self::stars(5, 'إدارة الوقت'),
                self::stars(6, 'التقييم العام للمدرّب'),
                self::q(7, 'textarea', 'ما أبرز ما أعجبك في أداء المدرّب؟'),
                self::q(8, 'textarea', 'ما الذي يمكن أن يحسّنه المدرّب؟'),
            ],
        );
    }

    private static function studentFeedback(): array
    {
        return self::template('student_feedback', 'ملاحظات الطلاب', 'feedback', null,
            'استطلاع قصير لآراء الطلاب في تجربتهم التعليمية على المنصة.',
            [
                self::stars(1, 'رضاك العام عن تجربتك التعليمية'),
                self::choice(2, 'checkbox', 'ما الذي تستخدمه أكثر؟', ['الدورات', 'الورش', 'المسارات التعليمية', 'الموارد والملفات'], false),
                self::choice(3, 'radio', 'هل واجهت صعوبة في استخدام المنصة؟', ['لا', 'أحياناً', 'كثيراً']),
                self::q(4, 'textarea', 'صف الصعوبة التي واجهتها', self::showWhen(3, 'not_equals', 'لا')),
                self::q(5, 'textarea', 'ما الذي تتمنى أن نضيفه؟'),
            ],
        );
    }

    private static function volunteerApplication(): array
    {
        return self::template('volunteer_application', 'طلب تطوع', 'community', 'volunteer_register',
            'جمع بيانات المهتمين بالتطوع ومجالات اهتمامهم وأوقات توفرهم.',
            [
                ...self::contact(1),
                self::q(4, 'text', 'المدينة'),
                self::choice(5, 'checkbox', 'مجالات التطوع التي تهمك', ['التعليم', 'تنظيم الفعاليات', 'الإعلام والتصميم', 'الترجمة', 'الدعم التقني', 'أخرى']),
                self::q(6, 'text', 'حدد المجال الآخر', self::showWhen(5, 'contains', 'أخرى')),
                self::choice(7, 'radio', 'عدد الساعات المتاحة أسبوعياً', ['أقل من 3 ساعات', '3 إلى 6 ساعات', 'أكثر من 6 ساعات']),
                self::q(8, 'textarea', 'خبراتك التطوعية السابقة'),
                self::required(self::q(9, 'consent', 'أوافق على معالجة بياناتي لغرض التطوع')),
            ],
            'طلبات التطوع الرسمية وملفات المتطوعين ونقاطهم تدار من وحدة المتطوعين. استخدم القالب لجولات استقطاب خاصة أو لفعالية محددة، لا بديلاً عن مسار التطوع.',
        );
    }

    private static function ambassadorApplication(): array
    {
        return self::template('ambassador_application', 'طلب الانضمام إلى برنامج السفراء', 'community', null,
            'جمع اهتمام المرشحين لبرنامج السفراء قبل التقديم الرسمي.',
            [
                ...self::contact(1),
                self::required(self::q(4, 'text', 'الجامعة أو الجهة')),
                self::q(5, 'url', 'رابط حسابك على وسائل التواصل'),
                self::required(self::q(6, 'textarea', 'لماذا تريد أن تكون سفيراً؟')),
                self::q(7, 'textarea', 'أفكار لأنشطة يمكنك تنفيذها'),
            ],
            'طلبات السفراء الرسمية (الملفات، المراجعة، حالات القبول) تتم من وحدة طلبات السفراء. هذا القالب لاستطلاع الاهتمام فقط.',
        );
    }

    private static function consultantApplication(): array
    {
        return self::template('consultant_application', 'طلب الانضمام كمستشار', 'community', null,
            'جمع اهتمام الخبراء الراغبين في تقديم الاستشارات.',
            [
                ...self::contact(1),
                self::required(self::q(4, 'text', 'مجال الخبرة')),
                self::q(5, 'number', 'سنوات الخبرة', ['config' => ['validation' => ['min' => 0, 'max' => 60, 'integer_only' => true]]]),
                self::q(6, 'url', 'رابط السيرة المهنية أو LinkedIn'),
                self::q(7, 'textarea', 'نبذة عن خبراتك'),
            ],
            'طلبات المستشارين الرسمية تدار من وحدة طلبات المستشارين. استخدم القالب لاستطلاع الاهتمام فقط.',
        );
    }

    private static function partnershipRequest(): array
    {
        return self::template('partnership_request', 'طلب شراكة', 'partnerships', 'partnership_request',
            'استقبال اهتمام الجهات بالتعاون مع المنصة.',
            [
                self::required(self::q(1, 'text', 'اسم الجهة')),
                self::required(self::q(2, 'text', 'اسم مسؤول التواصل')),
                self::required(self::q(3, 'email', 'البريد الإلكتروني')),
                self::q(4, 'phone', 'رقم الهاتف'),
                self::q(5, 'url', 'الموقع الإلكتروني'),
                self::choice(6, 'radio', 'نوع الشراكة المقترحة', ['تعليمية', 'رعاية', 'تقنية', 'إعلامية', 'أخرى']),
                self::required(self::q(7, 'textarea', 'وصف مختصر للتعاون المقترح')),
            ],
            'طلبات الشراكة الرسمية وملفات الشركاء وبرامجهم تدار من وحدة الشراكات. استخدم القالب لحملة أو فعالية بعينها.',
        );
    }

    private static function eventRegistration(): array
    {
        return self::template('event_registration', 'تسجيل في فعالية', 'events', null,
            'التسجيل في فعالية أو لقاء مع خيار الحضور والاحتياجات الخاصة.',
            [
                ...self::contact(1),
                self::choice(4, 'radio', 'طريقة الحضور', ['حضورياً', 'عن بعد']),
                self::choice(5, 'yes_no', 'هل لديك احتياجات خاصة للحضور؟', [], false),
                self::q(6, 'textarea', 'اذكر احتياجاتك لنستعد لها', self::showWhen(5, 'equals', 'yes')),
                self::q(7, 'textarea', 'أسئلة تود طرحها في الفعالية'),
            ],
        );
    }

    private static function attendanceConfirmation(): array
    {
        return self::template('attendance_confirmation', 'تأكيد حضور', 'events', null,
            'تأكيد حضور موعد أو فعالية مسبقاً.',
            [
                self::required(self::q(1, 'text', 'الاسم الكامل')),
                self::choice(2, 'radio', 'هل ستحضر؟', ['نعم، سأحضر', 'لا أستطيع الحضور', 'لم أقرر بعد']),
                self::q(3, 'number', 'عدد المرافقين', ['config' => ['validation' => ['min' => 0, 'max' => 10, 'integer_only' => true]]] + self::showWhen(2, 'equals', 'نعم، سأحضر')),
                self::q(4, 'textarea', 'سبب الاعتذار (اختياري)', self::showWhen(2, 'equals', 'لا أستطيع الحضور')),
            ],
        );
    }

    private static function internalHrRequest(): array
    {
        return self::template('internal_hr_request', 'طلب داخلي للموارد البشرية', 'hr', null,
            'طلب داخلي من الموظفين أو المتطوعين إلى فريق الموارد البشرية.',
            [
                self::required(self::q(1, 'text', 'الاسم')),
                self::q(2, 'text', 'القسم'),
                self::choice(3, 'select', 'نوع الطلب', ['شهادة خبرة', 'تحديث بيانات', 'استفسار', 'أخرى']),
                self::required(self::q(4, 'textarea', 'تفاصيل الطلب')),
                self::q(5, 'file', 'مرفق داعم'),
            ],
            'إذا كان الطلب يمر بمسار الموارد البشرية القائم (طلبات HR والمقابلات والتعيين) فاستخدم وحدة الموارد البشرية. القالب مناسب للطلبات البسيطة التي لا يغطيها ذلك المسار.',
            reviewStep: true,
        );
    }

    private static function leaveRequest(): array
    {
        return self::template('leave_request', 'طلب إجازة', 'hr', null,
            'طلب إجازة مع التواريخ والسبب ومن يغطي المهام.',
            [
                self::required(self::q(1, 'text', 'الاسم')),
                self::choice(2, 'radio', 'نوع الإجازة', ['سنوية', 'مرضية', 'طارئة', 'أخرى']),
                self::required(self::q(3, 'date', 'تاريخ البداية')),
                self::required(self::q(4, 'date', 'تاريخ النهاية')),
                self::q(5, 'text', 'من يغطي مهامك أثناء الإجازة؟'),
                self::q(6, 'textarea', 'ملاحظات'),
                self::q(7, 'file', 'مرفق (مثل تقرير طبي)', self::showWhen(2, 'equals', 'مرضية')),
            ],
            'إن كانت الإجازات تُعتمد في وحدة الموارد البشرية لديكم فالقرار والرصيد يبقيان هناك؛ القالب يجمع الطلب فقط ولا يحتسب رصيداً.',
            reviewStep: true,
        );
    }

    private static function reimbursementRequest(): array
    {
        return self::template('reimbursement_request', 'طلب استرداد مصروفات', 'finance', null,
            'طلب استرداد مبلغ صُرف لصالح نشاط، مع الإيصال.',
            [
                self::required(self::q(1, 'text', 'الاسم')),
                self::required(self::q(2, 'text', 'النشاط أو المشروع')),
                self::required(self::q(3, 'date', 'تاريخ الصرف')),
                self::required(self::q(4, 'number', 'المبلغ', ['config' => ['validation' => ['min' => 0]]])),
                self::required(self::q(5, 'textarea', 'وصف المصروف')),
                self::required(self::q(6, 'file', 'الإيصال')),
            ],
            'القالب يجمع الطلب ولا يسجل مصروفاً ولا يصرف مالاً. اعتماد المصروفات وتسجيلها يتم في الوحدة المالية.',
            reviewStep: true,
        );
    }

    private static function generalSurvey(): array
    {
        return self::template('general_survey', 'استطلاع رأي عام', 'general', null,
            'استطلاع قصير قابل للتعديل لأي موضوع.',
            [
                self::q(1, 'section', 'عن الموضوع'),
                self::stars(2, 'ما تقييمك العام؟'),
                self::choice(3, 'radio', 'كم مرة تستخدم هذه الخدمة؟', ['يومياً', 'أسبوعياً', 'شهرياً', 'نادراً']),
                self::q(4, 'textarea', 'ما الذي أعجبك أكثر؟'),
                self::q(5, 'textarea', 'ما الذي يمكن تحسينه؟'),
            ],
        );
    }

    private static function complaint(): array
    {
        return self::template('complaint', 'شكوى', 'support', 'complaint',
            'استقبال شكوى مع تفاصيلها ومرفقاتها.',
            [
                ...self::contact(1, phone: false),
                self::choice(3, 'select', 'تتعلق الشكوى بـ', ['دورة أو ورشة', 'مدرّب', 'الدفع', 'المنصة التقنية', 'أخرى']),
                self::required(self::q(4, 'textarea', 'تفاصيل الشكوى')),
                self::q(5, 'date', 'تاريخ حدوث المشكلة'),
                self::q(6, 'file', 'مرفق داعم'),
            ],
            'الشكاوى التي تحتاج متابعة وردوداً وتصعيداً تدار عبر نظام التذاكر والدعم. استخدم القالب لجمع الشكاوى في سياق محدد، ثم حوّل ما يلزم إلى تذكرة.',
        );
    }

    private static function suggestion(): array
    {
        return self::template('suggestion', 'اقتراح', 'support', 'suggestion',
            'استقبال الاقتراحات والأفكار لتحسين الخدمات.',
            [
                self::q(1, 'text', 'الاسم (اختياري)'),
                self::choice(2, 'select', 'مجال الاقتراح', ['المحتوى التعليمي', 'الفعاليات', 'المنصة التقنية', 'التواصل', 'أخرى']),
                self::required(self::q(3, 'textarea', 'اقتراحك')),
                self::q(4, 'textarea', 'كيف سيساعد اقتراحك؟'),
            ],
        );
    }

    private static function contactRequest(): array
    {
        return self::template('contact_request', 'طلب تواصل', 'support', null,
            'نموذج تواصل عام يصل فريقك مباشرة.',
            [
                ...self::contact(1),
                self::required(self::q(4, 'text', 'الموضوع')),
                self::required(self::q(5, 'textarea', 'الرسالة')),
                self::choice(6, 'radio', 'طريقة التواصل المفضلة', ['البريد الإلكتروني', 'الهاتف'], false),
            ],
        );
    }

    private static function meetingFeedback(): array
    {
        return self::template('meeting_feedback', 'تقييم اجتماع', 'feedback', 'meeting_form',
            'تقييم سريع للاجتماع بعد انتهائه.',
            [
                self::q(1, 'text', 'عنوان الاجتماع'),
                self::stars(2, 'مدى فائدة الاجتماع'),
                self::choice(3, 'yes_no', 'هل كان جدول الأعمال واضحاً؟', []),
                self::choice(4, 'radio', 'مدة الاجتماع كانت', ['قصيرة', 'مناسبة', 'طويلة']),
                self::q(5, 'textarea', 'ملاحظات للاجتماع القادم'),
            ],
        );
    }
}
