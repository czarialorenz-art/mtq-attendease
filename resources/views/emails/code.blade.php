<!DOCTYPE html>
<html>
<body style="margin:0;padding:24px;background:#F5F9F8;font-family:Arial,Helvetica,sans-serif;color:#0F2523;">
  <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:14px;padding:28px;">
    <h2 style="margin:0 0 6px;color:#0E3E3B;">MTQ-ATTENDEASE</h2>
    <p>Hi {{ $name }},</p>
    <p>
      @if ($purpose === 'email_change')
        Use this code to confirm your new email address:
      @else
        Use this code to verify your email address and finish signing in:
      @endif
    </p>
    <p style="font-size:32px;letter-spacing:8px;font-weight:bold;text-align:center;background:#EAF7F5;border-radius:10px;padding:14px;margin:18px 0;">{{ $code }}</p>
    <p style="font-size:13px;color:#3A5652;">The code expires in {{ $minutes }} minutes. Never share it with anyone — MTQ-ATTENDEASE staff will never ask for it.</p>
    <p style="font-size:13px;color:#7C948F;">If you didn't request this, you can ignore this email; no one can use your email address without this code.</p>
  </div>
</body>
</html>
