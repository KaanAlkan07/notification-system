<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Notifications\Delivery\TwilioSms;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Twilio\Security\RequestValidator;

final class TwilioReceiptController
{
    public function __invoke(Request $request, string $id, string $generation, string $attempt): Response
    {
        $token = (string) config('notifications.twilio.auth_token');
        $account = (string) config('notifications.twilio.account_sid');
        abort_if($token === '' || $account === '', 404);
        abort_unless(strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) === 'application/x-www-form-urlencoded', 415);
        abort_if($request->getQueryString() !== null, 403);

        // Signatures cover the original values, before Laravel trims or converts input.
        parse_str($request->getContent(), $fields);
        abort_if(array_filter($fields, 'is_array') !== [], 422);
        $url = TwilioSms::callbackUrl($id, (int) $generation, (int) $attempt);
        abort_unless(
            (string) (int) $generation === $generation && (string) (int) $attempt === $attempt
            && (new RequestValidator($token))->validate($request->header('X-Twilio-Signature', ''), $url, $fields)
            && ($fields['AccountSid'] ?? null) === $account,
            403,
        );

        $status = TwilioSms::deliveryStatus($fields['MessageStatus'] ?? null);
        $sid = $fields['MessageSid'] ?? null;
        abort_unless(is_string($sid) && preg_match(TwilioSms::SID_PATTERN, $sid), 422);
        if ($status === null) {
            return response()->noContent();
        }

        DB::transaction(function () use ($id, $generation, $attempt, $status, $sid, $fields): void {
            $notification = Notification::query()->lockForUpdate()->find($id);
            if ($notification === null || $notification->channel !== 'sms'
                || (string) $notification->retry_generation !== $generation
                || (string) $notification->attempts !== $attempt
                || ! in_array($notification->delivery_status, ['awaiting_receipt', 'sent'], true)
                || ! in_array($notification->status, ['processing', 'accepted', 'uncertain'], true)
                || ($notification->provider_message_id !== null && $notification->provider_message_id !== $sid)
                || $notification->delivery_status === $status
            ) {
                return;
            }

            $now = now();
            $notification->update([
                'status' => 'accepted',
                'provider_message_id' => $sid,
                'failure_code' => null,
                'processing_started_at' => null,
                'finished_at' => $notification->status === 'accepted' ? $notification->finished_at : $now,
                'delivery_status' => $status,
                'delivery_error_code' => $status === 'undelivered' ? TwilioSms::errorCode($fields['ErrorCode'] ?? null) : null,
                'delivery_updated_at' => $now,
            ]);
        }, attempts: 3);

        return response()->noContent();
    }
}
