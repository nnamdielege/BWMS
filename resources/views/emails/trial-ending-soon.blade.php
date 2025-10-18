<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4F46E5; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; padding: 12px 24px; background: #4F46E5; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
        .warning { background: #FEF3C7; border-left: 4px solid #F59E0B; padding: 15px; margin: 20px 0; }
        .footer { text-align: center; color: #6B7280; font-size: 14px; margin-top: 30px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Your Trial is Ending Soon</h1>
        </div>
        
        <div class="content">
            <p>Hi {{ $user->name }},</p>
            
            <div class="warning">
                <strong>⏰ {{ $daysRemaining }} days remaining</strong> in your trial period
            </div>
            
            <p>Your trial of <strong>{{ $subscription->plan->name }}</strong> will end on <strong>{{ $subscription->trial_ends_at->format('F j, Y') }}</strong>.</p>
            
            <p>To continue enjoying uninterrupted access to all features, please add a payment method before your trial ends.</p>
            
            <h3>What happens next?</h3>
            <ul>
                <li>If you add a payment method, you'll be automatically billed ${{ number_format($subscription->plan->price ?? 0, 2) }}/{{ $subscription->plan->interval ?? 'month' }} when your trial ends</li>
                <li>If you don't add a payment method, your account will be downgraded to the free plan</li>
            </ul>
            
            <div style="text-align: center;">
                <a href="{{ config('app.url') }}/dashboard/billing" class="button">Add Payment Method</a>
            </div>
            
            <p>Questions? Just reply to this email and we'll be happy to help!</p>
            
            <p>Best regards,<br>The {{ config('app.name') }} Team</p>
        </div>
        
        <div class="footer">
            <p>You're receiving this email because you have an active trial.</p>
            <p>{{ config('app.name') }} | {{ config('app.url') }}</p>
        </div>
    </div>
</body>
</html>