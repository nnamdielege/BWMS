<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #EF4444; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; padding: 12px 24px; background: #EF4444; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
        .error-box { background: #FEE2E2; border-left: 4px solid #EF4444; padding: 15px; margin: 20px 0; }
        .warning-box { background: #FEF3C7; border-left: 4px solid #F59E0B; padding: 15px; margin: 20px 0; }
        .footer { text-align: center; color: #6B7280; font-size: 14px; margin-top: 30px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Payment Failed</h1>
        </div>
        
        <div class="content">
            <p>Hi {{ $user->name }},</p>
            
            <div class="error-box">
                <strong>⚠ We were unable to process your payment</strong>
            </div>
            
            <p>We attempted to charge your payment method for your {{ $subscription->plan->name }} subscription, but the payment failed.</p>
            
            <p><strong>Amount:</strong> ${{ number_format($amount, 2) }}</p>
            
            @if($failedAttempts >= 2)
                <div class="warning-box">
                    <strong>⚠ Warning:</strong> This is attempt #{{ $failedAttempts }} of 3. After 3 failed attempts, your subscription will be suspended.
                </div>
            @endif
            
            <h3>What you need to do:</h3>
            <ol>
                <li>Check that your payment method has sufficient funds</li>
                <li>Verify your card details are correct and up to date</li>
                <li>Update your payment method if needed</li>
            </ol>
            
            <h3>Common reasons for payment failure:</h3>
            <ul>
                <li>Insufficient funds</li>
                <li>Expired credit card</li>
                <li>Card limit reached</li>
                <li>Bank declined the transaction</li>
            </ul>
            
            <div style="text-align: center;">
                <a href="{{ config('app.url') }}/dashboard/billing" class="button">Update Payment Method</a>
            </div>
            
            <p>We'll automatically retry the payment in a few days, but we recommend updating your payment method as soon as possible to avoid any disruption to your service.</p>
            
            <p>If you need help, please contact us immediately.</p>
            
            <p>Best regards,<br>The {{ config('app.name') }} Team</p>
        </div>
        
        <div class="footer">
            <p>You're receiving this email because a payment failed on your account.</p>
            <p>{{ config('app.name') }} | {{ config('app.url') }}</p>
        </div>
    </div>
</body>
</html>