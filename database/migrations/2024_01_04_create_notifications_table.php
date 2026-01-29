<?php
// database/migrations/2024_01_04_create_notifications_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('team_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->string('contact_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('receive_notifications')->default(true);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->foreignId('team_id')->nullable()->constrained();
            $table->enum('type', [
                'PartidoProgramado',
                'CambioHorario',
                'CambioUbicacion',
                'RecordatorioPartido',
                'Cancelacion',
                'ResultadoPublicado',
                'ClasificacionActualizada',
                'NuevoAnuncio'
            ]);
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->boolean('read')->default(false);
            $table->timestamps();
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->boolean('email_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('push_enabled')->default(true);
            $table->integer('reminder_hours_before')->default(24);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('team_contacts');
    }
};