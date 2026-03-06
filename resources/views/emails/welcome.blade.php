<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bienvenido a MiCopa</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f5f9; margin: 0; padding: 24px; }
  .container { max-width: 560px; margin: 0 auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
  .header { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); padding: 40px 32px; text-align: center; }
  .header .icon { font-size: 48px; margin-bottom: 12px; }
  .header h1 { color: white; margin: 0; font-size: 26px; font-weight: 800; }
  .header p { color: rgba(255,255,255,0.85); margin: 8px 0 0; font-size: 15px; }
  .body { padding: 32px; }
  .body h2 { color: #1e293b; font-size: 20px; margin: 0 0 12px; }
  .body p { color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
  .info-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin: 20px 0; }
  .info-card .label { font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
  .info-card .value { font-size: 16px; font-weight: 600; color: #1e293b; }
  .info-card .url { font-size: 14px; color: #3b82f6; font-family: monospace; }
  .steps { margin: 24px 0; }
  .step { display: flex; gap: 16px; margin-bottom: 16px; align-items: flex-start; }
  .step-num { background: #1e40af; color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0; }
  .step-text h4 { color: #1e293b; margin: 0 0 4px; font-size: 14px; }
  .step-text p { color: #64748b; margin: 0; font-size: 13px; }
  .btn { display: block; background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; text-decoration: none; text-align: center; padding: 14px 28px; border-radius: 10px; font-weight: 700; font-size: 15px; margin: 24px 0; }
  .footer { background: #f8fafc; padding: 20px 32px; text-align: center; border-top: 1px solid #e2e8f0; }
  .footer p { color: #94a3b8; font-size: 12px; margin: 0; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="icon">⚽</div>
    <h1>¡Bienvenido a MiCopa!</h1>
    <p>Tu cancha ya está lista para gestionar torneos</p>
  </div>

  <div class="body">
    <h2>Hola, {{ $user->name }} 👋</h2>
    <p>Tu cuenta ha sido creada exitosamente. Ahora puedes gestionar torneos, equipos y jugadores desde tu panel de administración.</p>

    <div class="info-card">
      <div class="label">Tu cancha</div>
      <div class="value">{{ $tenant->name }}</div>
      <div class="label" style="margin-top:12px">Tu sitio público</div>
      <div class="url">{{ config('app.web_url', 'http://localhost:3000') }}/{{ $tenant->slug }}</div>
    </div>

    <div class="steps">
      <div class="step">
        <div class="step-num">1</div>
        <div class="step-text">
          <h4>Crea tu primer torneo</h4>
          <p>Ve a Torneos y configura las fechas, tipo y equipos participantes.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">2</div>
        <div class="step-text">
          <h4>Registra equipos y jugadores</h4>
          <p>Agrega los equipos y jugadores que participarán en tus torneos.</p>
        </div>
      </div>
      <div class="step">
        <div class="step-num">3</div>
        <div class="step-text">
          <h4>Comparte tu micrositio</h4>
          <p>Tus jugadores podrán ver resultados, tabla y estadísticas en tiempo real.</p>
        </div>
      </div>
    </div>

    <a href="{{ config('app.frontend_url', 'http://localhost:8100') }}/admin/dashboard" class="btn">
      Ir a mi panel →
    </a>

    <p style="font-size:13px; color:#94a3b8;">Estás en el plan <strong>Free</strong>. Puedes actualizar a Pro o Business en cualquier momento para desbloquear más funciones.</p>
  </div>

  <div class="footer">
    <p>© {{ date('Y') }} MiCopa · Plataforma de gestión de torneos</p>
    <p style="margin-top:4px">Si no creaste esta cuenta, ignora este correo.</p>
  </div>
</div>
</body>
</html>
