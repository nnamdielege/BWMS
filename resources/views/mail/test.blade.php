<x-mail::message>
# Test email

This is a test from {{ config('app.name') }}. If you can read it, email from BWMS is working: supplier purchase orders will be delivered the same way.

Sent {{ $sentAt }} via the "{{ $mailerName }}" mailer.
</x-mail::message>
