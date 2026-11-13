<?php

declare(strict_types=1);

namespace App\Notifications\Delivery;

use App\Models\Notification;
use App\Notifications\RequestTimestamp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class WebhookProvider
{
    public function send(Notification $notification): ProviderResult
    {
        try {
            $response = Http::acceptJson()
                ->withoutRedirecting()
                ->connectTimeout((float) config('notifications.provider_connect_timeout', 2))
                ->timeout((float) config('notifications.provider_timeout', 5))
                ->withHeaders([
                    'Idempotency-Key' => $notification->id,
                    'X-Correlation-ID' => $notification->correlation_id,
                ])
                ->post((string) config('notifications.provider_url'), [
                    'to' => $notification->recipient,
                    'channel' => $notification->channel,
                    'content' => $notification->content,
                ]);
        } catch (ConnectionException) {
            // The remote side may have accepted the request before the connection failed.
            return ProviderResult::uncertain('transport_error');
        }

        if ($response->status() === 202) {
            $messageId = $response->json('messageId');

            if (is_string($messageId) && Str::isUuid($messageId)
                && $response->json('status') === 'accepted'
                && RequestTimestamp::parse($response->json('timestamp')) !== null) {
                return ProviderResult::accepted($messageId);
            }

            return ProviderResult::uncertain('invalid_acceptance');
        }

        $failureCode = 'http_'.$response->status();

        return match (true) {
            in_array($response->status(), [429, 503], true) => ProviderResult::retryable(
                $failureCode,
                $response->header('Retry-After'),
            ),
            $response->clientError() && $response->status() !== 408 => ProviderResult::failed($failureCode),
            default => ProviderResult::uncertain($failureCode),
        };
    }
}
