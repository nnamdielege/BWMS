<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #10B981; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; padding: 12px 24px; background: #10B981; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
        .success-box { background: #D1FAE5; border-left: 4px solid: #10B981; padding: 15px; margin: 20px 0; }
        .features { background: white; padding: 20px; border-radius: 6px; margin: 20px 0; }
        .footer { text-align: center; color: #6B7280; font-size: 14px; margin-top: 30px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 Welcome!</h1>
        </div>
        
        <div class="content">
            <p>Hi {{ $user->name }},</p>
            
            <div class="success-box">
                <strong>✓ Your subscription is now active!</strong>
            </div>
            
            <p>Welcome to <strong>{{ $plan->name }}</strong>! We're excited to have you on board.</p>
            
            <div class="features">
                <h3>What's included in your plan:</h3>
                <ul>
                    <li>Full access to all premium features</li>
                    <li>Priority customer support</li>
                    <li>Regular updates and improvements</li>
                    <li>Secure data storage</li>
                </ul>
            </div>
            
            <h3>Getting started:</h3>
            <ol>
                <li>Explore all the features in your dashboard</li>
                <li>Set up your account preferences</li>
                <li>Check out our help center for tips and tricks</li>
            </ol>
            
            <div style="text-align: center;">
                <a href="{{ config('app.url') }}/dashboard" class="button">Go to Dashboard</a>
            </div>
            
            <p>If you have any questions or need help getting started, our support team is here for you.</p>
            
            <p>Thank you for choosing {{ config('app.name') }}!</p>
            
            <p>Best regards,<br>The {{ config('app.name') }} Team</p>
        </div>
        
        <div class="footer">
            <p>You're receiving this email because your subscription was activated.</p>
            <p>{{ config('app.name') }} | {{ config('app.url') }}</p>
        </div>
    </div>
</body>
</html>