<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;background:#f4f8fb;font-family:Arial,Helvetica,sans-serif;color:#172554;">
    <div style="max-width:640px;margin:0 auto;padding:28px 18px;">
        <div style="background:#ffffff;border-radius:18px;border:1px solid #dbeafe;overflow:hidden;">
            <div style="padding:22px 26px;background:#eff6ff;border-bottom:1px solid #dbeafe;">
                <div style="font-weight:800;font-size:18px;color:#0f5cff;">Riva</div>
                <div style="font-size:12px;color:#64748b;margin-top:4px;">Smart Healthcare Monitoring</div>
            </div>
            <div style="padding:26px;">
                <h1 style="margin:0 0 14px;font-size:22px;line-height:1.35;color:#0f172a;">{{ $title }}</h1>
                <p style="margin:0;font-size:15px;line-height:1.7;color:#334155;white-space:pre-line;">{{ $body }}</p>

                @if ($actionUrl)
                    <div style="margin-top:26px;">
                        <a href="{{ $actionUrl }}" style="display:inline-block;background:#0f5cff;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:13px 20px;border-radius:12px;">
                            Open in Riva
                        </a>
                    </div>
                @endif
            </div>
            <div style="padding:18px 26px;background:#f8fafc;font-size:12px;color:#64748b;">
                This is an automated Riva healthcare notification. If this is urgent, contact the patient directly.
            </div>
        </div>
    </div>
</body>
</html>
