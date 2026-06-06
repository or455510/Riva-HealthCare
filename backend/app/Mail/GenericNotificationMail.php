<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class GenericNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $actionUrl = null
    ) {
    }

    public function build(): self
    {
        $frontendBase = rtrim((string) config('services.frontend.url', env('FRONTEND_URL', config('app.url'))), '/');
        $actionHref = $this->actionUrl && str_starts_with($this->actionUrl, '/')
            ? $frontendBase . $this->actionUrl
            : $this->actionUrl;

        return $this->subject('Riva - ' . $this->title)
            ->view('emails.notification')
            ->with([
                'title' => $this->title,
                'body' => $this->body,
                'actionUrl' => $actionHref,
            ]);
    }
}
