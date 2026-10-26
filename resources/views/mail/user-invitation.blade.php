<x-mail::message>
# Hi {{ $name }},

You've been invited to {{ config('app.name') }}, the Local Selection warehouse system.

<x-mail::button :url="$url">
Set your password
</x-mail::button>

This link expires on {{ $expiresAt }} (Brisbane time) and can be used once.
If you weren't expecting this, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
