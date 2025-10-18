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
        .success-box { background: #D1FAE5; border-left: 4px solid #10B981; padding: 15px; margin: 20px 0; }
        .details { background: white; padding: 20px; border-radius: 6px; margin: 20px 0; }
        .details-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #E5E7EB; }
        .footer { text-align: center; color: #6B7280; font-size: 14px; margin-top: 30px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✓ Payment Successful</h1>
        </div>
        
        <div class="content">
            <p>Hi {{ $user->name }},</p>
            
            <div class="success-box">
                <strong>✓ Your payment was successful!</strong>
            </div>
            
            <p>Thank you for your payment. Your subscription has been renewed.</p>
            
            <div class="details">
                <h3>Payment Details</h3>
                <div class="details-row">
                    <span><strong>Plan:</strong></span>
                    <span>{{ $subscription->plan->name }}</span>
                </div>
                <div class="details-row">
                    <span><strong>Amount:</strong></span>
                    <span>${{ number_format($amount, 2) }}</span>
                </div>
                <div class="details-row">
                    <span><strong>Payment Date:</strong></span>
                    <span>{{ now()->format('F j, Y') }}</span>
                </div>
                <div class="details-row">
                    <span><strong>Next Billing Date:</strong></span>
                    <span>{{ $nextBillingDate ? $nextBillingDate->format('F j, Y') : 'N/A' }}</span>
                </div>
            </div>
            
            <p>Your subscription will automatically renew on {{ $nextBillingDate ? $nextBillingDate->format('F j, Y') : 'your next billing date' }}.</p>
            
            <div style="text-align: center;">
                <a href="{{ config('app.url') }}/dashboard/billing" class="button">View Billing History</a>
            </div>
            
            <p>If you have any questions about your payment, please don't hesitate to contact us.</p>
            
            <p>Best regards,<br>The {{ config('app.name') }} Team</p>
        </div>
        
        <div class="footer">
            <p>You're receiving this email because a payment was processed on your account.</p>
            <p>{{ config('app.name') }} | {{ config('app.url') }}</p>
        </div>
    </div>
</body>
</html>