<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Restablece tu contraseña</title>
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
  .btn { display: block; background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; text-decoration: none; text-align: center; padding: 14px 28px; border-radius: 10px; font-weight: 700; font-size: 15px; margin: 24px 0; }
  .warning { background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 14px 18px; font-size: 13px; color: #92400e; }
  .footer { background: #f8fafc; padding: 20px 32px; text-align: center; border-top: 1px solid #e2e8f0; }
  .footer p { color: #94a3b8; font-size: 12px; margin: 0; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="icon">🔐</div>
    <h1>Restablecer contraseña</h1>
    <p>Recibimos una solicitud para tu cuenta</p>
  </div>

  <div class="body">
    <h2>Hola, {{ $user->name }}</h2>
    <p>Recibimos una solicitud para restablecer la contraseña de tu cuenta en MiCopa. Haz clic en el botón para crear una nueva contraseña:</p>

    <a href="{{ $resetUrl }}" class="btn">
      Restablecer mi contraseña →
    </a>

    <div class="warning">
      ⚠️ Este enlace expirará en <strong>60 minutos</strong>. Si no solicitaste este cambio, puedes ignorar este correo — tu contraseña no cambiará.
    </div>

    <p style="font-size:13px; color:#94a3b8; margin-top:20px;">
      Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
      <span style="color:#3b82f6; word-break:break-all;">{{ $resetUrl }}</span>
    </p>
  </div>

  <div class="footer">
    <p>© {{ date('Y') }} MiCopa · Plataforma de gestión de torneos</p>
    <p style="margin-top:4px">Si no solicitaste este correo, ignóralo de forma segura.</p>
  </div>
</div>
</body>
</html>
