<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #DC2626; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; padding: 12px 24px; background: #DC2626; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
        .critical-box { background: #FEE2E2; border-left: 4px solid #DC2626; padding: 15px; margin: 20px 0; }
        .footer { text-align: center; color: #6B7280; font-size: 14px; margin-top: 30px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Subscription Suspended</h1>
        </div>
        
        <div class="content">
            <p>Hi {{ $user->name }},</p>
            
            <div class="critical-box">
                <strong>⛔ Your subscription has been suspended</strong>
            </div>
            
            <p>Unfortunately, after 3 failed payment attempts, we've had to suspend your <strong>{{ $subscription->plan->name }}</strong> subscription.</p>
            
            <h3>What this means:</h3>
            <ul>
                <li>Your access to premium features has been restricted</li>
                <li>Your data is safe and will be retained</li>
                <li>You can reactivate anytime by updating your payment method</li>
            </ul>
            
            <h3>To reactivate your subscription:</h3>
            <ol>
                <li>Log in to your account</li>
                <li>Go to Billing settings</li>
                <li>Update your payment method</li>
                <li>Your subscription will be reactivated immediately</li>
            </ol>
            
            <div style="text-align: center;">
                <a href="{{ config('app.url') }}/dashboard/billing" class="button">Reactivate Subscription</a>
            </div>
            
            <p>We understand that payment issues happen. If you need any assistance or have questions about your account, please don't hesitate to reach out to our support team.</p>
            
            <p>Best regards,<br>The {{ config('app.name') }} Team</p>
        </div>
        
        <div class="footer">
            <p>You're receiving this email because your subscription was suspended.</p>
            <p>{{ config('app.name') }} | {{ config('app.url') }}</p>
        </div>
    </div>
</body>
</html>