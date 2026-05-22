@extends('emails.layout')

@section('body')
<p>Hi,</p>
<p>Someone requested to change the email on a <strong>{{ $brand->name }}</strong> account to this address. Click below to confirm. This link expires in 60 minutes.</p>
<p style="margin:32px 0;">
<a href="{{ $link }}" style="display:inline-block;background:{{ $brand->accentHex }};color:#fff;text-decoration:none;padding:14px 22px;border-radius:8px;font-weight:600;">Confirm new email</a>
</p>
<p style="font-size:14px;color:#555;">Or paste this URL into your browser:<br><span style="word-break:break-all;color:#333;">{{ $link }}</span></p>
<p>If you didn't request this, you can safely ignore the email.</p>
@endsection
