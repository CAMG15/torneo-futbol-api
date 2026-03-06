<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Suscripción cancelada</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f5f9; margin: 0; padding: 24px; }
  .container { max-width: 560px; margin: 0 auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
  .header { background: linear-gradient(135deg, #64748b 0%, #94a3b8 100%); padding: 40px 32px; text-align: center; }
  .header .icon { font-size: 48px; margin-bottom: 12px; }
  .header h1 { color: white; margin: 0; font-size: 26px; font-weight: 800; }
  .header p { color: rgba(255,255,255,0.85); margin: 8px 0 0; font-size: 15px; }
  .body { padding: 32px; }
  .body h2 { color: #1e293b; font-size: 20px; margin: 0 0 12px; }
  .body p { color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
  .alert { background: #fef3c7; border: 1px solid #fde68a; border-radius: 12px; padding: 16px 20px; margin: 20px 0; display: flex; gap: 12px; align-items: flex-start; }
  .alert .icon { font-size: 20px; flex-shrink: 0; }
  .alert p { color: #92400e; font-size: 14px; margin: 0; }
  .btn { display: block; background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; text-decoration: none; text-align: center; padding: 14px 28px; border-radius: 10px; font-weight: 700; font-size: 15px; margin: 24px 0; }
  .btn-outline { display: block; border: 2px solid #e2e8f0; color: #475569; text-decoration: none; text-align: center; padding: 12px 28px; border-radius: 10px; font-weight: 600; font-size: 14px; margin-bottom: 12px; }
  .footer { background: #f8fafc; padding: 20px 32px; text-align: center; border-top: 1px solid #e2e8f0; }
  .footer p { color: #94a3b8; font-size: 12px; margin: 0; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="icon">📋</div>
    <h1>Suscripción cancelada</h1>
    <p>Tu plan seguirá activo hasta {{ $activeUntil }}</p>
  </div>

  <div class="body">
    <h2>Hola, {{ $user->name }}</h2>
    <p>Hemos procesado la cancelación de tu suscripción para la cancha <strong>{{ $tenant->name }}</strong>.</p>

    <div class="alert">
      <div class="icon">⚠️</div>
      <p>Tu plan actual seguirá activo hasta el <strong>{{ $activeUntil }}</strong>. Después de esa fecha, tu cuenta cambiará automáticamente al plan gratuito.</p>
    </div>

    <p>Con el plan Free podrás seguir usando MiCopa, pero con las siguientes limitaciones:</p>
    <ul style="color:#475569; font-size:14px; line-height:1.8; padding-left:20px;">
      <li>Máximo 1 torneo activo</li>
      <li>Sin personalización de colores y logo</li>
      <li>Sin exportar PDF/Excel</li>
      <li>Publicidad de MiCopa en tu micrositio</li>
    </ul>

    <p>Si cambias de opinión, puedes reactivar tu suscripción en cualquier momento.</p>

    <a href="{{ config('app.frontend_url', 'http://localhost:8100') }}/admin/billing" class="btn">
      Reactivar suscripción →
    </a>
    <a href="{{ config('app.frontend_url', 'http://localhost:8100') }}/admin/dashboard" class="btn-outline">
      Ir a mi panel
    </a>
  </div>

  <div class="footer">
    <p>© {{ date('Y') }} MiCopa · Si tienes dudas escríbenos a soporte@micopa.live</p>
  </div>
</div>
</body>
</html>
