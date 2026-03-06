<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Tenant $tenant,
        public string $activeUntil,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu suscripción ha sido cancelada - MiCopa',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-cancelled',
        );
    }
}
