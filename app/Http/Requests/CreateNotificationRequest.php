<?php

namespace App\Http\Requests;

use App\Models\Notification;
use App\Notifications\RequestTimestamp;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class CreateNotificationRequest extends FormRequest
{
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : [];
    }

    public function rules(): array
    {
        $batch = $this->isBatch();
        $rules = $batch ? ['notifications' => ['required', 'array', 'list', 'min:1', 'max:1000']] : [];
        $items = $this->validationData()['notifications'] ?? null;
        if ($batch && (! is_array($items) || ! array_is_list($items) || count($items) > 1000)) {
            return $rules;
        }
        if ($batch) {
            if (! is_array(json_decode($this->getContent())->notifications ?? null)) {
                $rules['notifications'][] = static function (string $attribute, mixed $value, Closure $fail): void {
                    $fail('The notifications field must be a JSON array.');
                };

                return $rules;
            }
            $rules['notifications.*'] = ['required', 'array'];
        }

        $prefix = $batch ? 'notifications.*.' : '';
        $timestamp = function (string $attribute, mixed $value, Closure $fail): void {
            $date = RequestTimestamp::parse($value);
            if ($date === null || RequestTimestamp::ceilToSecond($date)->year > 9999) {
                $fail('The :attribute must be an RFC 3339 timestamp.');
            }
        };

        return $rules + [
            $prefix.'recipient' => ['bail', 'required', 'string', 'max:255'],
            $prefix.'channel' => ['bail', 'required', 'string', Rule::in(Notification::CHANNELS)],
            $prefix.'content' => ['bail', 'required', 'string', 'max:10000', 'not_regex:/\x00/'],
            $prefix.'priority' => ['bail', 'sometimes', 'required', 'string', Rule::in(Notification::PRIORITIES)],
            $prefix.'scheduled_at' => ['bail', 'sometimes', 'required', 'string', $timestamp],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('notifications')) {
                    return;
                }

                $payload = $this->validationData();
                $items = $this->isBatch() ? $payload['notifications'] : [$payload];
                foreach ($items as $index => $item) {
                    $prefix = $this->isBatch() ? "notifications.$index." : '';
                    $channel = $item['channel'] ?? null;
                    if (! in_array($channel, Notification::CHANNELS, true)) {
                        continue;
                    }

                    $recipientRules = match ($channel) {
                        'sms' => ['regex:/\A\+[1-9][0-9]{1,14}\z/'],
                        'email' => ['email:rfc', 'not_regex:/[\x00-\x1f\x7f]/'],
                        'push' => ['regex:/\A[\x21-\x7e]{1,255}\z/'],
                    };
                    $limit = match ($channel) {
                        'sms' => 160,
                        'push' => 240,
                        'email' => 10000,
                    };

                    if (! $validator->errors()->has($prefix.'recipient') && validator(
                        ['recipient' => $item['recipient']], ['recipient' => $recipientRules],
                    )->fails()) {
                        $validator->errors()->add($prefix.'recipient', "The recipient is invalid for $channel.");
                    }

                    if (! $validator->errors()->has($prefix.'content') && mb_strlen($item['content']) > $limit) {
                        $validator->errors()->add($prefix.'content', "The content may not exceed $limit characters for $channel.");
                    }
                }
            },
        ];
    }

    public function items(): array
    {
        $data = $this->validated();

        return array_map(static fn (array $item): array => [
            'recipient' => $item['recipient'],
            'channel' => $item['channel'],
            'content' => $item['content'],
            'priority' => $item['priority'] ?? 'normal',
            'scheduled_at' => RequestTimestamp::parse($item['scheduled_at'] ?? null),
        ], $this->isBatch() ? $data['notifications'] : [$data]);
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');
        if ($key !== null && ! preg_match('/\A[\x21-\x7e]{1,128}\z/', $key)) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'The Idempotency-Key must contain 1–128 visible ASCII characters.',
            ]);
        }

        return $key;
    }

    public function isBatch(): bool
    {
        return $this->routeIs('batches.store');
    }
}
