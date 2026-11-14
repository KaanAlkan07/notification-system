<?php

namespace App\Http\Requests;

use App\Models\Notification;
use App\Notifications\RequestTimestamp;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ListNotificationsRequest extends FormRequest
{
    public function rules(): array
    {
        $timestamp = function (string $attribute, mixed $value, Closure $fail): void {
            if (RequestTimestamp::parse($value) === null) {
                $fail('The :attribute must be an RFC 3339 timestamp.');
            }
        };

        return [
            'status' => ['sometimes', 'required', 'string', Rule::in(Notification::STATUSES)],
            'delivery_status' => ['sometimes', 'required', 'string', Rule::in(Notification::DELIVERY_STATUSES)],
            'channel' => ['sometimes', 'required', 'string', Rule::in(Notification::CHANNELS)],
            'from' => ['sometimes', 'required', 'string', $timestamp],
            'to' => ['sometimes', 'required', 'string', $timestamp],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'required', 'string', 'max:250', 'regex:/\A[A-Za-z0-9_-]+\z/'],
        ];
    }

    public function filters(): array
    {
        $filter = $this->validated();
        $from = RequestTimestamp::parse($filter['from'] ?? null);
        $to = RequestTimestamp::parse($filter['to'] ?? null);
        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            throw ValidationException::withMessages(['to' => 'The to timestamp must be on or after from.']);
        }

        if ($from !== null) {
            $from = RequestTimestamp::ceilToSecond($from);
            if ($from->year > 9999) {
                throw ValidationException::withMessages(['from' => 'The from timestamp exceeds the supported range.']);
            }
            $filter['from'] = $from->format('Y-m-d H:i:s');
        }
        if ($to !== null) {
            $filter['to'] = $to->format('Y-m-d H:i:s');
        }
        if (isset($filter['cursor'])) {
            $parameters = json_decode(base64_decode(strtr($filter['cursor'], '-_', '+/'), true) ?: '', true);
            if (! is_array($parameters) || array_keys($parameters) !== ['id', '_pointsToNextItems'] ||
                ! is_string($parameters['id']) || ! Str::isUuid($parameters['id']) ||
                $parameters['_pointsToNextItems'] !== true) {
                throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
            }
            $filter['cursor'] = new Cursor(['id' => $parameters['id']]);
        }

        return $filter;
    }
}
