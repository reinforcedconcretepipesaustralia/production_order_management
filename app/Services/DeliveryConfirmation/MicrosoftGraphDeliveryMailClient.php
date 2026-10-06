<?php

namespace App\Services\DeliveryConfirmation;

use App\Exceptions\DeliveryConfirmationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class GraphMailboxMessage
{
    public function __construct(
        public string $id,
        public string $subject,
        public string $bodyText,
        public string $receivedAt,
        public ?string $fromAddress,
        public string $mailbox,
    ) {}
}

class MicrosoftGraphDeliveryMailClient
{
    public function send(DeliveryConfirmationMessage $message, string $mailbox): string
    {
        $mailbox = $this->normalizeMailbox($mailbox);
        $accessToken = $this->accessToken();
        $graphBaseUrl = rtrim($this->requiredGraphConfig('graph_base_url'), '/');

        try {
            $sendResponse = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post($graphBaseUrl.'/users/'.rawurlencode($mailbox).'/sendMail', [
                    'message' => [
                        'subject' => $message->subject,
                        'body' => [
                            'contentType' => 'HTML',
                            'content' => $message->htmlBody,
                        ],
                        'toRecipients' => [
                            ['emailAddress' => ['address' => $message->recipient]],
                        ],
                    ],
                    'saveToSentItems' => true,
                ]);
        } catch (ConnectionException) {
            throw DeliveryConfirmationException::unavailable();
        }

        if ($sendResponse->status() !== 202) {
            throw $this->responseException($sendResponse);
        }

        return $sendResponse->header('request-id')
            ?: $sendResponse->header('client-request-id')
            ?: $message->trackingToken;
    }

    /**
     * @return list<GraphMailboxMessage>
     */
    public function inboxMessagesSince(string $mailbox, string $iso8601): array
    {
        $mailbox = $this->normalizeMailbox($mailbox);
        $accessToken = $this->accessToken();
        $graphBaseUrl = rtrim($this->requiredGraphConfig('graph_base_url'), '/');
        $filter = "receivedDateTime ge {$iso8601}";
        $url = $graphBaseUrl.'/users/'.rawurlencode($mailbox)
            .'/mailFolders/inbox/messages'
            .'?$filter='.rawurlencode($filter)
            .'&$select=id,subject,body,bodyPreview,from,receivedDateTime'
            .'&$orderby='.rawurlencode('receivedDateTime asc')
            .'&$top=50';

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->get($url);
        } catch (ConnectionException) {
            throw DeliveryConfirmationException::unavailable();
        }

        if (! $response->successful()) {
            throw $this->responseException($response);
        }

        $messages = [];

        foreach ($response->json('value') ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = (string) ($item['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $body = $item['body'] ?? [];
            $content = is_array($body) ? (string) ($body['content'] ?? '') : '';
            if ($content === '') {
                $content = (string) ($item['bodyPreview'] ?? '');
            }

            $from = $item['from']['emailAddress']['address'] ?? null;

            $messages[] = new GraphMailboxMessage(
                id: $id,
                subject: (string) ($item['subject'] ?? ''),
                bodyText: strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $content)),
                receivedAt: (string) ($item['receivedDateTime'] ?? ''),
                fromAddress: is_string($from) ? strtolower($from) : null,
                mailbox: $mailbox,
            );
        }

        return $messages;
    }

    private function normalizeMailbox(string $mailbox): string
    {
        $mailbox = strtolower(trim($mailbox));

        if ($mailbox === '' || ! filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
            throw DeliveryConfirmationException::configuration();
        }

        return $mailbox;
    }

    private function accessToken(): string
    {
        $tenantId = $this->requiredGraphConfig('tenant_id');
        $clientId = $this->requiredGraphConfig('client_id');
        $clientSecret = $this->requiredGraphConfig('client_secret');
        $loginBaseUrl = rtrim($this->requiredGraphConfig('login_base_url'), '/');

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
            throw DeliveryConfirmationException::unavailable();
        }

        if (! $tokenResponse->successful()) {
            throw $this->responseException($tokenResponse);
        }

        $accessToken = $tokenResponse->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            throw DeliveryConfirmationException::authentication();
        }

        return $accessToken;
    }

    private function requiredGraphConfig(string $key): string
    {
        $value = config("delivery-reminders.graph.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw DeliveryConfirmationException::configuration();
        }

        return trim($value);
    }

    private function retryTransientFailures(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException
                && ($exception->response->status() === 429 || $exception->response->serverError()));
    }

    private function responseException(Response $response): DeliveryConfirmationException
    {
        if ($response->status() === 401 || $response->status() === 403) {
            return DeliveryConfirmationException::authentication();
        }

        if ($response->serverError() || $response->status() === 429) {
            return DeliveryConfirmationException::unavailable();
        }

        return DeliveryConfirmationException::transport();
    }
}
