@component('mail::message')
# طلب برنامج تدريبي جديد

تم استلام طلب برنامج تدريبي جديد من قبل المركز. فيما يلي التفاصيل:

---

## بيانات المتقدم

| الحقل | القيمة |
|-------|--------|
| الاسم | {{ $workshopRequest->requester_name }} |
| البريد الإلكتروني | {{ $workshopRequest->requester_email }} |
| رقم الهاتف | {{ $workshopRequest->requester_phone ?? 'غير محدد' }} |
| القسم | {{ $workshopRequest->requester_department ?? 'غير محدد' }} |

---

## بيانات البرنامج

**اسم البرنامج:** {{ $workshopRequest->program_name }}

**اسم المتحدث:** {{ $workshopRequest->speaker_name }}

**المسمى الوظيفي:** {{ $workshopRequest->speaker_job_title }}

@if($speakerPhotoUrl)
**صورة المتحدث:**
![صورة المتحدث]({{ $speakerPhotoUrl }})
@endif

---

## تفاصيل البرنامج

**محاور البرنامج:**
{{ $workshopRequest->topics }}

**الفئة المستهدفة:**
{{ $workshopRequest->target_audience }}

**التاريخ المقترح:** {{ $workshopRequest->proposed_date->format('Y-m-d') }}

**التوقيت المقترح:** {{ $workshopRequest->proposed_time }}

**نوع المكان/المنصة:**
@foreach($workshopRequest->location_types ?? [] as $locationType)
- {{ $locationType }}
@endforeach

**التصنيفات:**
@foreach($workshopRequest->categories ?? [] as $category)
- {{ $category }}
@endforeach

**نوع الرسوم:** {{ $workshopRequest->price_type === 'free' ? 'مجاني' : 'مدفوع' }}

@if($workshopRequest->price_type === 'paid')
**قيمة الرسوم:** {{ $workshopRequest->price_amount }} ريال
@endif

---

## الإعلان المُنتج

{{ $workshopRequest->generated_announcement_text }}

---

تم إنشاء هذا الطلب بواسطة نظام إدارة برامج EMC.

@endcomponent
