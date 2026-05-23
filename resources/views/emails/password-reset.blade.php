@extends('emails.layout')

@section('body')
<p>Hi{{ $user->name ? ' ' . $user->name : '' }},</p>
<p>We got a request to reset your password for <strong>{{ config('app.name') }}</strong>. Click the button below to set a new one. This link expires in 60 minutes.</p>
<p style="margin:32px 0;">
<a href="{{ $link }}" style="display:inline-block;background:{{ config('services.mail_brand.accent_hex', '#4f46e5') }};color:#fff;text-decoration:none;padding:14px 22px;border-radius:8px;font-weight:600;">Reset password</a>
</p>
<p style="font-size:14px;color:#555;">Or paste this URL into your browser:<br><span style="word-break:break-all;color:#333;">{{ $link }}</span></p>
<p>If you didn't request this, you can safely ignore the email.</p>
@endsection
