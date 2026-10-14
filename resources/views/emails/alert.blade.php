<!DOCTYPE html>
<html>
<body style="margin:0;padding:24px;background:#F5F9F8;font-family:Arial,Helvetica,sans-serif;color:#0F2523;">
  <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:14px;padding:28px;">
    <h2 style="margin:0 0 6px;color:#0E3E3B;">MTQ-ATTENDEASE</h2>
    <h3 style="margin:14px 0 8px;color:#C24C4C;">{{ $title }}</h3>
    <p>Hi {{ $name }},</p>
    <p>{{ $body }}</p>
    <table style="font-size:13px;color:#3A5652;margin:12px 0;">
      @foreach ($details as $label => $value)
        @if ($value)
          <tr><td style="padding:2px 12px 2px 0;font-weight:bold;">{{ $label }}</td><td>{{ $value }}</td></tr>
        @endif
      @endforeach
    </table>
    <p style="font-size:13px;color:#3A5652;"><strong>Was this you?</strong> If yes, no action is needed. If not, change your password right away and tell your teacher or the school's IT office.</p>
  </div>
</body>
</html>
