<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Your Verification Code</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f6f8; margin: 0; padding: 24px;">
    <div style="max-width: 500px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 32px; border: 1px solid #e0e5ea;">
        <h2 style="color: #132b45; margin-top: 0;">NubiBilling Verification</h2>
        <p style="color: #4a5568; font-size: 15px; line-height: 1.5;">
            Thank you for registering. Please use the following 6-digit verification code to complete your verification:
        </p>
        <div style="text-align: center; margin: 28px 0;">
            <span style="font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #132b45; background: #f4f6f8; padding: 12px 24px; border-radius: 6px; display: inline-block;">
                {{ $otp }}
            </span>
        </div>
        <p style="color: #718096; font-size: 13px;">
            This code will expire in 10 minutes. If you did not request this code, please ignore this email.
        </p>
    </div>
</body>
</html>
