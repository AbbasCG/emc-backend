<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1e293b; }
        h1 { font-size: 16px; margin-bottom: 4px; }
        p.meta { font-size: 9px; color: #64748b; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: right; }
        th { background: #f1f5f9; font-weight: bold; }
        tr:nth-child(even) { background: #f8fafc; }
    </style>
</head>
<body>
    <h1>سجل التدقيق — EMC</h1>
    <p class="meta">تم التصدير: {{ $exported }} سجل — {{ now()->format('Y-m-d H:i') }}</p>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>المستخدم</th>
                <th>الدور</th>
                <th>الإجراء</th>
                <th>الكيان</th>
                <th>IP</th>
                <th>التاريخ</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['id'] }}</td>
                    <td>{{ $row['actor_name'] }}</td>
                    <td>{{ $row['actor_role'] }}</td>
                    <td>{{ $row['action_label'] ?? $row['action'] }}</td>
                    <td>{{ $row['entity_type'] }} {{ $row['entity_name'] ?? '' }}</td>
                    <td>{{ $row['ip_address'] ?? '—' }}</td>
                    <td>{{ $row['created_at'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
