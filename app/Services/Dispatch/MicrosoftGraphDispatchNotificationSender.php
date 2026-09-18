<?php

namespace App\Services\Dispatch;

use App\Contracts\DispatchNotificationSender;
use App\Exceptions\DispatchNotificationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class MicrosoftGraphDispatchNotificationSender implements DispatchNotificationSender
{
    public function send(DispatchNotification $notification): DispatchNotificationResult
    {
        $tenantId = $this->requiredConfig('tenant_id');
        $clientId = $this->requiredConfig('client_id');
        $clientSecret = $this->requiredConfig('client_secret');
        $sender = $this->requiredConfig('sender');
        $loginBaseUrl = rtrim($this->requiredConfig('login_base_url'), '/');
        $graphBaseUrl = rtrim($this->requiredConfig('graph_base_url'), '/');

        try {
            $tokenResponse = Http::asForm()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->retry([200, 500], 0, $this->retryTransientFailures(...), throw: false)
                ->post("{$loginBaseUrl}/{$tenantId}/oauth2/v2.0/token", [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]);
        } catch (ConnectionException) {
            throw DispatchNotificationException::unavailable();
        }

        if (! $tokenResponse->successful()) {
            throw $this->responseException($tokenResponse);
        }

        $accessToken = $tokenResponse->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            throw DispatchNotificationException::authentication();
        }

        try {
            $sendResponse = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post($graphBaseUrl.'/users/'.rawurlencode($sender).'/sendMail', [
                    'message' => [
                        'subject' => $notification->subject,
                        'body' => [
                            'contentType' => 'HTML',
                            'content' => $notification->htmlBody,
                        ],
                        'toRecipients' => array_map(
                            fn (string $recipient): array => [
                                'emailAddress' => ['address' => $recipient],
                            ],
                            $notification->recipients,
                        ),
                    ],
                    'saveToSentItems' => true,
                ]);
        } catch (ConnectionException) {
            throw DispatchNotificationException::transportUncertain();
        }

        if ($sendResponse->status() !== 202) {
            throw $this->responseException($sendResponse);
        }

        return new DispatchNotificationResult(
            providerRequestId: $sendResponse->header('request-id')
                ?: $sendResponse->header('client-request-id'),
        );
    }

    private function requiredConfig(string $key): string
    {
        $value = config("dispatch-notifications.graph.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw DispatchNotificationException::configuration();
        }

        return trim($value);
    }

    private function retryTransientFailures(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException
                && ($exception->response->status() === 429 || $exception->response->serverError()));
    }

    private function responseException(Response $response): DispatchNotificationException
    {
        return match (true) {
            in_array($response->status(), [401, 403], true) => DispatchNotificationException::authentication(),
            $response->status() === 429 => DispatchNotificationException::rateLimited(),
            $response->serverError() => DispatchNotificationException::unavailable(),
            default => DispatchNotificationException::rejected(),
        };
    }
}
