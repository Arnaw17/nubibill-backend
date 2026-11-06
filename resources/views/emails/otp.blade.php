<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $purpose === 'FORGOT_PASSWORD' ? 'Reset Your NubiBilling Password' : 'Verify Your NubiBilling Account' }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f1f5f9; padding: 32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); border: 1px solid #e2e8f0;">
                    <!-- Brand Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 28px 32px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 700; letter-spacing: 0.5px;">
                                NubiBilling<span style="color: #38bdf8;">.</span>
                            </h1>
                            <p style="margin: 4px 0 0 0; color: #94a3b8; font-size: 13px; text-transform: uppercase; letter-spacing: 1px;">
                                Enterprise ERP Platform
                            </p>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 32px 32px 24px 32px;">
                            <h2 style="margin: 0 0 12px 0; color: #0f172a; font-size: 20px; font-weight: 600; text-align: center;">
                                @if ($purpose === 'FORGOT_PASSWORD')
                                    Password Reset Verification
                                @else
                                    Verify Your Account
                                @endif
                            </h2>
                            <p style="margin: 0 0 24px 0; color: #475569; font-size: 15px; line-height: 1.6; text-align: center;">
                                @if ($purpose === 'FORGOT_PASSWORD')
                                    We received a request to reset your NubiBilling account password. Use the verification code below to authorize this request:
                                @else
                                    Thank you for registering with NubiBilling. Please use the verification code below to verify your email address and continue:
                                @endif
                            </p>

                            <!-- OTP Box -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 24px 0;">
                                <tr>
                                    <td align="center">
                                        <div style="display: inline-block; background-color: #f8fafc; border: 2px dashed #0284c7; border-radius: 10px; padding: 18px 32px; text-align: center;">
                                            <span style="font-family: 'Courier New', Courier, monospace; font-size: 34px; font-weight: 800; letter-spacing: 10px; color: #0284c7; margin-right: -10px; display: inline-block;">
                                                {{ $otp }}
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <!-- Expiry Info -->
                            <p style="margin: 0 0 20px 0; color: #64748b; font-size: 13px; text-align: center; line-height: 1.5;">
                                ⏱️ This verification code is valid for <strong>{{ $expiresInMinutes ?? 5 }} minutes</strong>.
                            </p>

                            <!-- Security Warning -->
                            <div style="background-color: #fffbeb; border-left: 4px solid #f59e0b; border-radius: 4px; padding: 12px 16px; margin: 20px 0 0 0;">
                                <p style="margin: 0; color: #92400e; font-size: 12px; line-height: 1.5;">
                                    <strong>Security Notice:</strong> Never share this code with anyone. NubiBilling support staff and administrators will never ask for your verification code or password.
                                </p>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 32px; text-align: center;">
                            <p style="margin: 0 0 6px 0; color: #94a3b8; font-size: 12px;">
                                If you did not make this request, you can safely ignore this email. No changes will be made to your account.
                            </p>
                            <p style="margin: 0; color: #cbd5e1; font-size: 11px;">
                                &copy; {{ date('Y') }} NubiBilling ERP. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
