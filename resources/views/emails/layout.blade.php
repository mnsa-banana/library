<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $brand->name }}</title>
</head>
<body style="margin:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1a1a1a;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f5f5;padding:24px 0;">
<tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:12px;overflow:hidden;border-top:6px solid {{ $brand->accentHex }};">
<tr><td style="padding:32px 32px 8px 32px;">
@if($brand->mailLogoUrl)
<img src="{{ $brand->mailLogoUrl }}" alt="{{ $brand->name }}" height="32" style="display:block;margin-bottom:16px;">
@else
<div style="font-weight:700;font-size:18px;color:{{ $brand->accentHex }};margin-bottom:16px;">{{ $brand->name }}</div>
@endif
</td></tr>
<tr><td style="padding:0 32px 32px 32px;font-size:16px;line-height:1.55;">
@yield('body')
</td></tr>
<tr><td style="padding:16px 32px 32px 32px;font-size:12px;color:#888;border-top:1px solid #eee;">
You received this email because someone — hopefully you — used <strong>{{ $brand->name }}</strong>. If you didn't expect it, you can safely ignore it.
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
