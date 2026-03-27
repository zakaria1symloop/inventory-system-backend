<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>رمز التحقق — تراكسيرا</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: 'Segoe UI', Tahoma, Arial, sans-serif; direction: rtl;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; padding: 40px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="max-width: 520px; width: 100%;">

                    {{-- Header with logo --}}
                    <tr>
                        <td style="background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); border-radius: 16px 16px 0 0; padding: 32px 40px; text-align: center;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 auto;">
                                <tr>
                                    <td style="vertical-align: middle; padding-left: 10px;">
                                        <span style="font-size: 22px; font-weight: 800; color: #ffffff; letter-spacing: 0.5px;">تراكسيرا</span>
                                    </td>
                                    <td style="vertical-align: middle;">
                                        <div style="width: 38px; height: 38px; background-color: rgba(255,255,255,0.15); border-radius: 10px; text-align: center; line-height: 38px;">
                                            <img src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cGF0aCBkPSJNMjAgN2wtOC00LTggNG0xNiAwbC04IDRtOC00djEwbC04IDRtMC0xMEw0IDdtOCA0djEwTTQgN3YxMGw4IDQiIHN0cm9rZT0id2hpdGUiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIi8+PC9zdmc+" alt="" width="20" height="20" style="vertical-align: middle;">
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Main content --}}
                    <tr>
                        <td style="background-color: #ffffff; padding: 40px 40px 32px;">
                            {{-- Greeting --}}
                            <p style="margin: 0 0 8px; font-size: 20px; font-weight: 700; color: #111827;">
                                @if($type === 'password_reset')
                                    إعادة تعيين كلمة المرور
                                @else
                                    تأكيد البريد الإلكتروني
                                @endif
                            </p>

                            <p style="margin: 0 0 28px; font-size: 15px; color: #6b7280; line-height: 1.7;">
                                @if($userName)
                                    مرحباً {{ $userName }}،
                                @else
                                    مرحباً،
                                @endif
                                <br>
                                @if($type === 'password_reset')
                                    لقد طلبت إعادة تعيين كلمة المرور الخاصة بك. استخدم الرمز أدناه لإتمام العملية.
                                @else
                                    شكراً لتسجيلك في تراكسيرا. استخدم الرمز أدناه لتأكيد بريدك الإلكتروني.
                                @endif
                            </p>

                            {{-- OTP Box --}}
                            <div style="text-align: center; margin: 0 0 28px;">
                                <div style="display: inline-block; background-color: #f0f5ff; border: 2px dashed #93b4f8; border-radius: 14px; padding: 24px 40px;">
                                    <p style="margin: 0 0 8px; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 1px;">رمز التحقق</p>
                                    <p style="margin: 0; font-size: 36px; font-weight: 800; color: #2563eb; letter-spacing: 12px; font-family: 'Courier New', monospace; direction: ltr;">{{ $otp }}</p>
                                </div>
                            </div>

                            {{-- Timer note --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" style="width: 100%; margin: 0 0 24px;">
                                <tr>
                                    <td style="background-color: #fef3c7; border-radius: 10px; padding: 14px 18px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="vertical-align: top; padding-left: 10px;">
                                                    <span style="font-size: 18px;">&#9200;</span>
                                                </td>
                                                <td>
                                                    <p style="margin: 0; font-size: 13px; color: #92400e; line-height: 1.5;">
                                                        هذا الرمز صالح لمدة <strong>10 دقائق</strong> فقط. لا تشاركه مع أي شخص.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            {{-- Security note --}}
                            <p style="margin: 0; font-size: 13px; color: #9ca3af; line-height: 1.6;">
                                @if($type === 'password_reset')
                                    إذا لم تطلب إعادة تعيين كلمة المرور، يمكنك تجاهل هذا البريد بأمان.
                                @else
                                    إذا لم تنشئ حساباً في تراكسيرا، يمكنك تجاهل هذا البريد بأمان.
                                @endif
                            </p>
                        </td>
                    </tr>

                    {{-- Divider --}}
                    <tr>
                        <td style="background-color: #ffffff; padding: 0 40px;">
                            <div style="border-top: 1px solid #e5e7eb;"></div>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background-color: #ffffff; border-radius: 0 0 16px 16px; padding: 24px 40px 32px; text-align: center;">
                            <p style="margin: 0 0 6px; font-size: 14px; font-weight: 700; color: #374151;">تراكسيرا</p>
                            <p style="margin: 0; font-size: 12px; color: #9ca3af;">إدارة ذكية لأعمالك</p>
                        </td>
                    </tr>

                    {{-- Bottom text --}}
                    <tr>
                        <td style="padding: 20px 40px; text-align: center;">
                            <p style="margin: 0; font-size: 11px; color: #9ca3af; line-height: 1.6;">
                                هذا البريد تم إرساله تلقائياً من تراكسيرا. لا ترد على هذا البريد.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
