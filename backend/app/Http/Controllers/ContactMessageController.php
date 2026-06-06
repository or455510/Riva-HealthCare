<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ContactMessageController extends Controller
{
    private const ADMIN_EMAIL = 'or455510@gmail.com';
    private const ADMIN_WHATSAPP = '+201012077945';

    public function store(Request $request)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $contactMessage = ContactMessage::create($data);

        Log::info('Contact message saved', [
            'contact_message_id' => $contactMessage->id,
            'sender_email' => $contactMessage->email,
            'subject' => $contactMessage->subject,
        ]);

        $this->sendAdminEmail($contactMessage);
        $whatsappSent = $this->sendWhatsAppNotification($contactMessage);

        $message = $whatsappSent
            ? 'Message saved, email sent, and WhatsApp notification sent.'
            : 'Message saved and email sent. WhatsApp not configured or failed.';

        return $this->success([
            'id' => $contactMessage->id,
            'admin_email_sent_at' => $contactMessage->admin_email_sent_at,
            'whatsapp_sent_at' => $contactMessage->whatsapp_sent_at,
            'whatsapp_sent' => $whatsappSent,
        ], $message, 201);
    }

    private function sendAdminEmail(ContactMessage $contactMessage): void
    {
        $body = implode("\n", [
            'New Riva contact message',
            '',
            'Sender name: '.$contactMessage->full_name,
            'Sender email: '.$contactMessage->email,
            'Subject: '.$contactMessage->subject,
            'Created time: '.$contactMessage->created_at?->format('Y-m-d H:i:s'),
            '',
            'Message:',
            $contactMessage->message,
        ]);

        Mail::raw($body, function ($message) use ($contactMessage) {
            $message
                ->to(self::ADMIN_EMAIL)
                ->replyTo($contactMessage->email, $contactMessage->full_name)
                ->subject('Riva Contact Message - '.$contactMessage->subject);
        });

        $contactMessage->forceFill(['admin_email_sent_at' => now()])->save();

        Log::info('Contact message admin email sent', [
            'contact_message_id' => $contactMessage->id,
            'admin_email' => self::ADMIN_EMAIL,
        ]);
    }

    private function sendWhatsAppNotification(ContactMessage $contactMessage): bool
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from = config('services.twilio.from');

        if (!$sid || !$token || !$from) {
            Log::warning('Contact message WhatsApp not configured', [
                'contact_message_id' => $contactMessage->id,
                'has_sid' => (bool) $sid,
                'has_token' => (bool) $token,
                'has_from' => (bool) $from,
            ]);

            return false;
        }

        $body = implode("\n", [
            'New Riva contact message',
            'From: '.$contactMessage->full_name.' <'.$contactMessage->email.'>',
            'Subject: '.$contactMessage->subject,
            'Time: '.$contactMessage->created_at?->format('Y-m-d H:i:s'),
            '',
            mb_substr($contactMessage->message, 0, 1200),
        ]);

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withBasicAuth($sid, $token)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => 'whatsapp:'.$from,
                    'To' => 'whatsapp:'.self::ADMIN_WHATSAPP,
                    'Body' => $body,
                ]);
        } catch (Throwable $exception) {
            Log::warning('Contact message WhatsApp failed', [
                'contact_message_id' => $contactMessage->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('Contact message WhatsApp failed', [
                'contact_message_id' => $contactMessage->id,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return false;
        }

        $contactMessage->forceFill(['whatsapp_sent_at' => now()])->save();

        Log::info('Contact message WhatsApp sent', [
            'contact_message_id' => $contactMessage->id,
            'admin_whatsapp' => self::ADMIN_WHATSAPP,
            'twilio_sid' => data_get($response->json(), 'sid'),
        ]);

        return true;
    }
}
