<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Team;
use App\Models\Matchs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class NotificationController extends Controller
{
    /**
     * Agregar contacto del equipo
     */
    public function addTeamContact(Request $request, Team $team)
    {
        $validated = $request->validate([
            'contact_name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'receive_notifications' => 'boolean'
        ]);

        DB::table('team_contacts')->insert([
            'team_id' => $team->id,
            'contact_name' => $validated['contact_name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'receive_notifications' => $validated['receive_notifications'] ?? true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return response()->json(['message' => 'Contacto agregado']);
    }

    /**
     * Configurar preferencias de notificación
     */
    public function setNotificationPreferences(Request $request, Team $team)
    {
        $validated = $request->validate([
            'email_enabled' => 'boolean',
            'sms_enabled' => 'boolean',
            'push_enabled' => 'boolean',
            'reminder_hours_before' => 'integer|min:1|max:168'
        ]);

        DB::table('notification_preferences')->updateOrInsert(
            ['team_id' => $team->id],
            array_merge($validated, [
                'updated_at' => now(),
                'created_at' => now()
            ])
        );

        return response()->json(['message' => 'Preferencias actualizadas']);
    }

    /**
     * Notificar partido programado
     */
    public function notifyMatchScheduled(Matchs $match)
    {
        $tournament = $match->matchday->tournament;
        $teams = [$match->homeTeam, $match->awayTeam];

        foreach ($teams as $team) {
            $opponent = $team->id === $match->home_team_id ? 
                $match->awayTeam : $match->homeTeam;
            
            $isHome = $team->id === $match->home_team_id;

            $notification = Notification::create([
                'tournament_id' => $tournament->id,
                'team_id' => $team->id,
                'type' => 'PartidoProgramado',
                'title' => 'Nuevo Partido Programado',
                'message' => sprintf(
                    'Tu equipo jugará %s contra %s el %s a las %s',
                    $isHome ? 'en casa' : 'de visita',
                    $opponent->name,
                    Carbon::parse($match->match_date)->format('d/m/Y'),
                    Carbon::parse($match->match_date)->format('H:i')
                ),
                'data' => [
                    'match_id' => $match->id,
                    'opponent' => $opponent->name,
                    'date' => $match->match_date,
                    'location' => $match->location
                ]
            ]);

            $this->sendNotification($notification, $team);
        }

        return response()->json(['message' => 'Notificaciones enviadas']);
    }

    /**
     * Notificar cambio de horario
     */
    public function notifyScheduleChange(Request $request, Matchs $match)
    {
        $validated = $request->validate([
            'old_date' => 'required|date',
            'new_date' => 'required|date',
            'reason' => 'nullable|string'
        ]);

        $teams = [$match->homeTeam, $match->awayTeam];

        foreach ($teams as $team) {
            $notification = Notification::create([
                'tournament_id' => $match->matchday->tournament_id,
                'team_id' => $team->id,
                'type' => 'CambioHorario',
                'title' => '⚠️ Cambio de Horario',
                'message' => sprintf(
                    'El partido contra %s ha cambiado de fecha. Nueva fecha: %s a las %s',
                    $team->id === $match->home_team_id ? $match->awayTeam->name : $match->homeTeam->name,
                    Carbon::parse($validated['new_date'])->format('d/m/Y'),
                    Carbon::parse($validated['new_date'])->format('H:i')
                ),
                'data' => [
                    'match_id' => $match->id,
                    'old_date' => $validated['old_date'],
                    'new_date' => $validated['new_date'],
                    'reason' => $validated['reason'] ?? null
                ]
            ]);

            $this->sendNotification($notification, $team);
        }

        return response()->json(['message' => 'Notificaciones de cambio enviadas']);
    }

    /**
     * Recordatorios automáticos
     */
    public function sendMatchReminders()
    {
        // Obtener partidos próximos (24 horas antes por defecto)
        $upcomingMatches = Matchs::where('status', 'Programado')
            ->whereBetween('match_date', [now(), now()->addHours(26)])
            ->with(['homeTeam', 'awayTeam', 'matchday.tournament'])
            ->get();

        $notificationsSent = 0;

        foreach ($upcomingMatches as $match) {
            $matchDate = Carbon::parse($match->match_date);
            $hoursUntilMatch = now()->diffInHours($matchDate, false);

            if ($hoursUntilMatch <= 24 && $hoursUntilMatch > 23) {
                $teams = [$match->homeTeam, $match->awayTeam];

                foreach ($teams as $team) {
                    // Verificar preferencias
                    $preferences = DB::table('notification_preferences')
                        ->where('team_id', $team->id)
                        ->first();

                    if (!$preferences || $preferences->push_enabled) {
                        $opponent = $team->id === $match->home_team_id ? 
                            $match->awayTeam : $match->homeTeam;

                        $notification = Notification::create([
                            'tournament_id' => $match->matchday->tournament_id,
                            'team_id' => $team->id,
                            'type' => 'RecordatorioPartido',
                            'title' => '⏰ Recordatorio de Partido',
                            'message' => sprintf(
                                'Tu partido contra %s es mañana a las %s en %s',
                                $opponent->name,
                                $matchDate->format('H:i'),
                                $match->location ?? 'ubicación por confirmar'
                            ),
                            'data' => [
                                'match_id' => $match->id,
                                'hours_until' => $hoursUntilMatch
                            ]
                        ]);

                        $this->sendNotification($notification, $team);
                        $notificationsSent++;
                    }
                }
            }
        }

        return response()->json([
            'message' => 'Recordatorios procesados',
            'notifications_sent' => $notificationsSent
        ]);
    }

    /**
     * Notificar a todos los equipos del torneo
     */
    public function broadcastToTournament(Request $request, $tournamentId)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|in:NuevoAnuncio,CambioHorario,Cancelacion'
        ]);

        $teams = DB::table('tournament_team')
            ->where('tournament_id', $tournamentId)
            ->pluck('team_id');

        $notificationsSent = 0;

        foreach ($teams as $teamId) {
            $notification = Notification::create([
                'tournament_id' => $tournamentId,
                'team_id' => $teamId,
                'type' => $validated['type'],
                'title' => $validated['title'],
                'message' => $validated['message']
            ]);

            $team = Team::find($teamId);
            $this->sendNotification($notification, $team);
            $notificationsSent++;
        }

        return response()->json([
            'message' => 'Notificación enviada a todos los equipos',
            'teams_notified' => $notificationsSent
        ]);
    }

    /**
     * Enviar notificación por múltiples canales
     */
    private function sendNotification($notification, $team)
    {
        $notification->sent_at = now();
        $notification->save();

        // Obtener preferencias
        $preferences = DB::table('notification_preferences')
            ->where('team_id', $team->id)
            ->first();

        // Email
        if ((!$preferences || $preferences->email_enabled)) {
            $this->sendEmailNotification($notification, $team);
        }

        // Push Notification (integrar con Firebase/OneSignal)
        if ((!$preferences || $preferences->push_enabled)) {
            $this->sendPushNotification($notification, $team);
        }

        // SMS (integrar con Twilio/similar)
        if ($preferences && $preferences->sms_enabled) {
            $this->sendSMSNotification($notification, $team);
        }
    }

    private function sendEmailNotification($notification, $team)
    {
        $contacts = DB::table('team_contacts')
            ->where('team_id', $team->id)
            ->where('receive_notifications', true)
            ->whereNotNull('email')
            ->get();

        foreach ($contacts as $contact) {
            try {
                Mail::raw($notification->message, function ($message) use ($contact, $notification) {
                    $message->to($contact->email)
                        ->subject($notification->title);
                });
            } catch (\Exception $e) {
                //\Log::error('Error sending email: ' . $e->getMessage());
            }
        }
    }

    private function sendPushNotification($notification, $team)
    {
        // Implementar integración con servicio de push
        // Ejemplo con Firebase Cloud Messaging
        
        /*
        $fcmTokens = DB::table('device_tokens')
            ->where('team_id', $team->id)
            ->pluck('fcm_token');

        foreach ($fcmTokens as $token) {
            // Enviar push notification
        }
        */
    }

    private function sendSMSNotification($notification, $team)
    {
        // Implementar integración con Twilio u otro servicio SMS
        
        /*
        $contacts = DB::table('team_contacts')
            ->where('team_id', $team->id)
            ->where('receive_notifications', true)
            ->whereNotNull('phone')
            ->get();

        foreach ($contacts as $contact) {
            // Enviar SMS
        }
        */
    }

    /**
     * Obtener notificaciones del equipo
     */
    public function getTeamNotifications(Team $team)
    {
        $notifications = Notification::where('team_id', $team->id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json($notifications);
    }

    /**
     * Marcar notificación como leída
     */
    public function markAsRead(Notification $notification)
    {
        $notification->read = true;
        $notification->save();

        return response()->json(['message' => 'Notificación marcada como leída']);
    }
}