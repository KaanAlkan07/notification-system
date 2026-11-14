<?php

declare(strict_types=1);

namespace App\Notifications\Delivery;

use App\Models\Notification;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;

final class SmtpEmail
{
    public function send(Notification $notification): ProviderResult
    {
        $sender = (string) config('mail.from.address');
        $config = config('mail.mailers.notification_smtp');
        if (! filter_var($sender, FILTER_VALIDATE_EMAIL)
            || ! in_array($config['scheme'], ['smtp', 'smtps'], true)
            || (string) $config['host'] === ''
            || $config['port'] < 1 || $config['port'] > 65535
            || (string) $config['username'] === ''
            || (string) $config['password'] === '') {
            return ProviderResult::failed('smtp_configuration');
        }

        try {
            $message = (new Email)
                ->from(new Address($sender, (string) config('mail.from.name')))
                ->to($notification->recipient)
                ->subject('Bildirim Merkezi')
                ->text($notification->content);
            $domain = substr(strrchr($sender, '@'), 1);
            $message->getHeaders()->addIdHeader('Message-ID', $notification->id.'.'.$notification->retry_generation.'@'.$domain);
            $sent = Mail::mailer('notification_smtp')->getSymfonyTransport()->send($message);
        } catch (RfcComplianceException) {
            return ProviderResult::failed('smtp_invalid_address');
        } catch (UnexpectedResponseException $error) {
            $code = $error->getCode();

            return match (true) {
                $code >= 400 && $code < 500 => ProviderResult::retryable('smtp_'.$code, null),
                $code >= 500 && $code < 600 => ProviderResult::failed('smtp_'.$code),
                default => ProviderResult::uncertain('smtp_unexpected_response'),
            };
        } catch (TransportExceptionInterface) {
            return ProviderResult::uncertain('smtp_transport_error');
        }

        if ($sent === null) {
            return ProviderResult::uncertain('smtp_no_receipt');
        }

        return ProviderResult::accepted(substr($sent->getMessageId(), 0, 255));
    }
}
