<?php

declare(strict_types=1);

namespace App\Notifications\Delivery;

use App\Models\Notification;
use App\Notifications\RequestTimestamp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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
                ->withHeaders($this->headers($notification))
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
            return $this->acceptance($response) ?? ProviderResult::uncertain('invalid_acceptance');
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

    public function reconciliationIdentity(): ?string
    {
        $url = config('notifications.provider_status_url');

        return filled($url) ? hash('sha256', config('notifications.provider_url')."\n".$url) : null;
    }

    public function lookup(Notification $notification): ?ProviderResult
    {
        try {
            $request = Http::acceptJson()
                ->withoutRedirecting()
                ->connectTimeout((float) config('notifications.provider_connect_timeout', 2))
                ->timeout((float) config('notifications.provider_timeout', 5))
                ->withHeaders($this->headers($notification));
            $token = config('notifications.provider_status_token');
            if (filled($token)) {
                $request->withToken($token);
            }
            $response = $request->get((string) config('notifications.provider_status_url'));
        } catch (ConnectionException) {
            return null;
        }

        if ($response->status() !== 200) {
            return null;
        }

        // A rejection guarantees the previous submission cannot still be accepted.
        return $response->json('status') === 'rejected' && $response->json('attempt') === $notification->attempts
            ? ProviderResult::retryable('provider_rejected', $response->header('Retry-After'))
            : $this->acceptance($response);
    }

    private function headers(Notification $notification): array
    {
        return [
            'Idempotency-Key' => $notification->retry_generation > 0
                ? $notification->id.':'.$notification->retry_generation
                : $notification->id,
            'X-Correlation-ID' => $notification->correlation_id,
            'X-Delivery-Attempt' => (string) $notification->attempts,
        ];
    }

    private function acceptance(Response $response): ?ProviderResult
    {
        $messageId = $response->json('messageId');
        $timestamp = $response->json('timestamp');
        if ($response->json('status') !== 'accepted' || ! is_string($messageId) || ! Str::isUuid($messageId) || ! is_string($timestamp)) {
            return null;
        }

        // PHP stores six fractional digits. The timestamp validates the acknowledgment.
        $timestamp = preg_replace('/(\.\d{6})\d+(?=Z\z|[+-]\d{2}:\d{2}\z)/', '$1', $timestamp);

        return RequestTimestamp::parse($timestamp) === null ? null : ProviderResult::accepted($messageId);
    }
}
