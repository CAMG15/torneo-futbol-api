<?php

namespace App\Mail;

use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Tenant $tenant,
        public Payment $payment,
        public string $planName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Pago confirmado - Plan ' . $this->planName . ' activado',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-confirmed',
        );
    }
}
