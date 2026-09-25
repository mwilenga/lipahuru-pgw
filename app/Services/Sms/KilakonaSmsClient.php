<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KilakonaSmsClient
{
    /**
     * @param  list<string>|null  $contacts  Defaults to configured admin recipients
     */
    public function send(string $message, ?array $contacts = null): void
    {
        $config = config('services.kilakona_sms', []);
        $apiKey = (string) ($config['api_key'] ?? '');
        $apiSecret = (string) ($config['api_secret'] ?? '');
        $sendUrl = (string) ($config['send_url'] ?? '');
        $senderId = (string) ($config['sender_id'] ?? '');
        $recipients = $contacts ?? ($config['recipients'] ?? []);

        if ($apiKey === '' || $apiSecret === '' || $sendUrl === '' || $senderId === '') {
            Log::warning('Kilakona SMS skipped: incomplete configuration.', [
                'has_api_key' => $apiKey !== '',
                'has_api_secret' => $apiSecret !== '',
                'has_send_url' => $sendUrl !== '',
                'has_sender_id' => $senderId !== '',
            ]);

            return;
        }

        if ($recipients === []) {
            Log::warning('Kilakona SMS skipped: no recipients configured (ADMIN_SMS_RECIPIENTS).');

            return;
        }

        $contactsCsv = implode(',', array_values(array_filter(array_map(
            static fn (mixed $number): string => trim((string) $number),
            $recipients,
        ))));

        if ($contactsCsv === '') {
            Log::warning('Kilakona SMS skipped: empty contacts after normalize.');

            return;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret,
                ])
                ->acceptJson()
                ->asJson()
                ->post($sendUrl, [
                    'senderId' => $senderId,
                    'messageType' => 'text',
                    'message' => $message,
                    'contacts' => $contactsCsv,
                ]);

            if ($response->failed()) {
                Log::warning('Kilakona SMS send failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'contacts' => $contactsCsv,
                ]);

                return;
            }

            Log::info('Kilakona SMS sent.', [
                'contacts' => $contactsCsv,
                'message_preview' => mb_substr($message, 0, 80),
                'response' => $response->json(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Kilakona SMS send exception.', [
                'message' => $exception->getMessage(),
                'contacts' => $contactsCsv,
            ]);
        }
    }
}
