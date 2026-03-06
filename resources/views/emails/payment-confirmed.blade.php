<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pago confirmado</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f5f9; margin: 0; padding: 24px; }
  .container { max-width: 560px; margin: 0 auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
  .header { background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%); padding: 40px 32px; text-align: center; }
  .header .icon { font-size: 48px; margin-bottom: 12px; }
  .header h1 { color: white; margin: 0; font-size: 26px; font-weight: 800; }
  .header p { color: rgba(255,255,255,0.85); margin: 8px 0 0; font-size: 15px; }
  .body { padding: 32px; }
  .body h2 { color: #1e293b; font-size: 20px; margin: 0 0 12px; }
  .body p { color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
  .receipt { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin: 20px 0; }
  .receipt-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
  .receipt-row:last-child { border-bottom: none; font-weight: 700; font-size: 16px; color: #1e293b; }
  .receipt-row .label { color: #64748b; }
  .receipt-row .value { color: #1e293b; }
  .plan-badge { display: inline-block; background: #dbeafe; color: #1e40af; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; margin-bottom: 16px; }
  .btn { display: block; background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; text-decoration: none; text-align: center; padding: 14px 28px; border-radius: 10px; font-weight: 700; font-size: 15px; margin: 24px 0; }
  .footer { background: #f8fafc; padding: 20px 32px; text-align: center; border-top: 1px solid #e2e8f0; }
  .footer p { color: #94a3b8; font-size: 12px; margin: 0; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="icon">✅</div>
    <h1>¡Pago confirmado!</h1>
    <p>Tu plan ha sido activado exitosamente</p>
  </div>

  <div class="body">
    <h2>Hola, {{ $user->name }}</h2>
    <p>Hemos recibido tu pago correctamente. Tu suscripción al plan <strong>{{ $planName }}</strong> ya está activa.</p>

    <div class="plan-badge">Plan {{ $planName }} activo</div>

    <div class="receipt">
      <div class="receipt-row">
        <span class="label">Cancha</span>
        <span class="value">{{ $tenant->name }}</span>
      </div>
      <div class="receipt-row">
        <span class="label">Plan</span>
        <span class="value">{{ $planName }}</span>
      </div>
      <div class="receipt-row">
        <span class="label">Método de pago</span>
        <span class="value">{{ ucfirst($payment->payment_provider) }}</span>
      </div>
      <div class="receipt-row">
        <span class="label">Referencia</span>
        <span class="value">#{{ $payment->provider_payment_id }}</span>
      </div>
      <div class="receipt-row">
        <span class="label">Fecha</span>
        <span class="value">{{ $payment->paid_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }}</span>
      </div>
      <div class="receipt-row">
        <span class="label">Total pagado</span>
        <span class="value">${{ number_format($payment->amount_cents / 100, 2) }} {{ $payment->currency }}</span>
      </div>
    </div>

    <p>Tu suscripción se renovará automáticamente cada mes. Puedes gestionar tu suscripción desde el panel de facturación.</p>

    <a href="{{ config('app.frontend_url', 'http://localhost:8100') }}/admin/billing" class="btn">
      Ver mi suscripción →
    </a>
  </div>

  <div class="footer">
    <p>© {{ date('Y') }} MiCopa · Conserva este correo como comprobante de pago.</p>
  </div>
</div>
</body>
</html>
