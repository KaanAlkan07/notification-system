<?php

declare(strict_types=1);

namespace App\Notifications\Delivery;

use App\Models\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class TwilioSms
{
    public const SID_PATTERN = '/\ASM[0-9a-fA-F]{32}\z/';

    public function send(Notification $notification): ProviderResult
    {
        $account = (string) config('notifications.twilio.account_sid');
        $token = (string) config('notifications.twilio.auth_token');
        $from = (string) config('notifications.twilio.from');
        $callback = self::callbackUrl($notification->id, $notification->retry_generation, $notification->attempts);
        $parts = parse_url($callback);
        if (! preg_match('/\AAC[0-9a-fA-F]{32}\z/', $account) || $token === ''
            || ! preg_match('/\A\+[1-9][0-9]{1,14}\z/', $from)
            || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))
        ) {
            return ProviderResult::failed('twilio_configuration');
        }

        try {
            $response = Http::asForm()->acceptJson()->withoutRedirecting()
                ->withBasicAuth($account, $token)
                ->connectTimeout((float) config('notifications.provider_connect_timeout', 2))
                ->timeout((float) config('notifications.provider_timeout', 5))
                ->post("https://api.twilio.com/2010-04-01/Accounts/$account/Messages.json", [
                    'To' => $notification->recipient,
                    'From' => $from,
                    'Body' => $notification->content,
                    'StatusCallback' => $callback,
                ]);
        } catch (ConnectionException) {
            return ProviderResult::uncertain('twilio_transport_error');
        }

        if ($response->status() === 201) {
            $sid = $response->json('sid');
            if (! is_string($sid) || ! preg_match(self::SID_PATTERN, $sid)) {
                return ProviderResult::uncertain('twilio_invalid_acceptance');
            }

            return ProviderResult::accepted(
                $sid, self::deliveryStatus($response->json('status')) ?? 'awaiting_receipt',
                self::errorCode($response->json('error_code')),
            );
        }

        $code = 'twilio_'.(self::errorCode($response->json('code')) ?? 'http_'.$response->status());

        return match (true) {
            $response->status() === 429 => ProviderResult::retryable($code, $response->header('Retry-After')),
            $response->clientError() && $response->status() !== 408 => ProviderResult::failed($code),
            default => ProviderResult::uncertain($code),
        };
    }

    public static function callbackUrl(string $id, int $generation, int $attempt): string
    {
        $base = rtrim((string) config('notifications.twilio.callback_base_url'), '/');

        return "$base/webhooks/twilio/sms/$id/$generation/$attempt";
    }

    public static function deliveryStatus(mixed $status): ?string
    {
        return match ($status) {
            'sent' => 'sent',
            'delivered' => 'delivered',
            'failed', 'undelivered', 'canceled' => 'undelivered',
            default => null,
        };
    }

    public static function errorCode(mixed $value): ?string
    {
        return (is_string($value) || is_int($value)) && preg_match('/\A[0-9]{1,10}\z/', (string) $value)
            ? (string) $value
            : null;
    }
}
