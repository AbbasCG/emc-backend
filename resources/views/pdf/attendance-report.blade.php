<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
<meta charset="UTF-8">
<style>
    body { font-family: 'DejaVu Sans', sans-serif; direction: rtl; text-align: right; font-size: 12px; color: #22334A; }
    .header { border-bottom: 3px solid #2691C2; padding-bottom: 10px; margin-bottom: 16px; }
    .header h1 { color: #22334A; font-size: 20px; margin: 0 0 4px; }
    .header p { color: #6b7a90; font-size: 11px; margin: 0; }
    .meta { margin-bottom: 14px; font-size: 11px; color: #444; }
    .kpis { display: table; width: 100%; margin-bottom: 16px; }
    .kpi { display: table-cell; width: 20%; text-align: center; border: 1px solid #e2e8f0; padding: 8px; }
    .kpi .value { font-size: 16px; font-weight: bold; }
    .kpi .label { font-size: 9px; color: #6b7a90; }
    table.rows { width: 100%; border-collapse: collapse; font-size: 10px; }
    table.rows th { background: #22334A; color: #fff; padding: 6px; text-align: right; }
    table.rows td { padding: 5px 6px; border-bottom: 1px solid #eef1f5; }
    .footer { position: fixed; bottom: -20px; left: 0; right: 0; font-size: 9px; color: #9aa5b1; text-align: center; }
</style>
</head>
<body>
    <div class="header">
        <h1>EMC — تقرير الحضور</h1>
        <p>الفترة: {{ $from }} إلى {{ $to }} — تاريخ الإنشاء: {{ $generatedAt->format('Y-m-d H:i') }}</p>
    </div>

    <div class="kpis">
        <div class="kpi"><div class="value">{{ $total }}</div><div class="label">الإجمالي</div></div>
        <div class="kpi"><div class="value">{{ $present }}</div><div class="label">حاضر</div></div>
        <div class="kpi"><div class="value">{{ $absent }}</div><div class="label">غائب</div></div>
        <div class="kpi"><div class="value">{{ $late }}</div><div class="label">متأخر</div></div>
        <div class="kpi"><div class="value">{{ $percentage }}%</div><div class="label">نسبة الحضور</div></div>
    </div>

    <table class="rows">
        <thead>
            <tr>
                <th>الطالب</th><th>الدورة</th><th>الجلسة</th><th>التاريخ</th><th>الحالة</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
            <tr>
                <td>{{ $row->user?->name }}</td>
                <td>{{ $row->session?->course?->title }}</td>
                <td>{{ $row->session?->title }}</td>
                <td>{{ $row->session?->session_date?->toDateString() }}</td>
                <td>{{ $statusLabel($row->status) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">EMC Platform — تقرير حضور مُولَّد آلياً</div>
</body>
</html>
