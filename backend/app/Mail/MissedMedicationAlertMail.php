<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class MissedMedicationAlertMail extends Mailable
{
    public function __construct(public readonly string $bodyText)
    {
    }

    public function build(): self
    {
        return $this->subject('Riva - Missed Medication Alert')
            ->html(nl2br(e($this->bodyText)));
    }
}
