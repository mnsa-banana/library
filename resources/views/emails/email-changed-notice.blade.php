@extends('emails.layout')

@section('body')
<p>Hi{{ $user->name ? ' ' . $user->name : '' }},</p>
<p>The email on your <strong>{{ $brand->name }}</strong> account was just changed to <strong>{{ $newEmailMasked }}</strong>.</p>
<p>If that was you, no action needed.</p>
<p>If it wasn't, reply to this email so we can lock things down.</p>
@endsection
