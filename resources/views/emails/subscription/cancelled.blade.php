<div style="font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto;">
    <h1 style="color: #dc2626;">Subscription Cancelled</h1>

    @if($scheduled)
        <p>Your subscription will be cancelled at the end of your billing period.</p>
        <p><strong>Cancellation Date:</strong> {{ $cancelsAt?->format('M d, Y') }}</p>
        <p>You will have access to your account until that date.</p>
    @else
        <p>Your subscription has been cancelled immediately.</p>
        <p>Your access will be revoked within 24 hours.</p>
    @endif

    @if($cancellationReason)
        <p><strong>Reason:</strong> {{ $cancellationReason }}</p>
    @endif

    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 2rem 0;">

    <h3>Need Help?</h3>
    <p>If you'd like to reactivate your subscription or have any questions, please contact our support team.</p>

    <p style="color: #6b7280; font-size: 0.875rem; margin-top: 2rem;">
        © {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
    </p>
</div>