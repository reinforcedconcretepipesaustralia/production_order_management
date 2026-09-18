<?php

namespace Tests\Feature;

use App\Exceptions\DispatchNotificationException;
use App\Services\Dispatch\DispatchNotification;
use App\Services\Dispatch\MicrosoftGraphDispatchNotificationSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DispatchNotificationSenderTest extends TestCase
{
    public function test_microsoft_graph_adapter_sends_the_expected_client_credentials_and_mail_requests(): void
    {
        $this->configureGraph();
        Http::preventStrayRequests();
        Http::fake([
            'https://login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://graph.microsoft.com/v1.0/users/dispatch%40example.com/sendMail' => Http::response(
                status: 202,
                headers: ['request-id' => 'graph-request-123'],
            ),
        ]);
        $notification = new DispatchNotification(
            recipients: ['customer@example.com', 'carrier@example.com'],
            subject: 'Dispatch load LOAD-100',
            htmlBody: '<p>Load summary</p>',
            idempotencyKey: str_repeat('a', 64),
        );

        $result = (new MicrosoftGraphDispatchNotificationSender)->send($notification);

        $this->assertSame('graph-request-123', $result->providerRequestId);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://login.microsoftonline.com/tenant-id/oauth2/v2.0/token'
                && $request['client_id'] === 'client-id'
                && $request['client_secret'] === 'test-client-secret'
                && $request['scope'] === 'https://graph.microsoft.com/.default'
                && $request['grant_type'] === 'client_credentials';
        });
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://graph.microsoft.com/v1.0/users/dispatch%40example.com/sendMail'
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && $request['message']['subject'] === 'Dispatch load LOAD-100'
                && $request['message']['body'] === [
                    'contentType' => 'HTML',
                    'content' => '<p>Load summary</p>',
                ]
                && $request['message']['toRecipients'] === [
                    ['emailAddress' => ['address' => 'customer@example.com']],
                    ['emailAddress' => ['address' => 'carrier@example.com']],
                ]
                && $request['saveToSentItems'] === true;
        });
    }

    public function test_missing_graph_configuration_fails_before_any_network_request(): void
    {
        $this->configureGraph();
        config()->set('dispatch-notifications.graph.tenant_id', null);
        Http::preventStrayRequests();

        try {
            (new MicrosoftGraphDispatchNotificationSender)->send($this->notification());
            $this->fail('Expected incomplete Graph configuration to fail.');
        } catch (DispatchNotificationException $exception) {
            $this->assertSame('configuration', $exception->category);
            $this->assertSame('Dispatch email provider configuration is incomplete.', $exception->getMessage());
            $this->assertStringNotContainsString('test-client-secret', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_graph_credential_rejection_does_not_expose_provider_or_client_secrets(): void
    {
        $this->configureGraph();
        Http::preventStrayRequests();
        Http::fake([
            'https://login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response([
                'error_description' => 'Invalid secret test-client-secret from Azure.',
            ], 401),
        ]);

        try {
            (new MicrosoftGraphDispatchNotificationSender)->send($this->notification());
            $this->fail('Expected rejected Graph credentials to fail.');
        } catch (DispatchNotificationException $exception) {
            $this->assertSame('authentication', $exception->category);
            $this->assertSame('The dispatch email provider rejected its credentials.', $exception->getMessage());
            $this->assertStringNotContainsString('test-client-secret', $exception->getMessage());
            $this->assertStringNotContainsString('Azure', $exception->getMessage());
        }
    }

    private function configureGraph(): void
    {
        config()->set('dispatch-notifications.graph', [
            'tenant_id' => 'tenant-id',
            'client_id' => 'client-id',
            'client_secret' => 'test-client-secret',
            'sender' => 'dispatch@example.com',
            'login_base_url' => 'https://login.microsoftonline.com',
            'graph_base_url' => 'https://graph.microsoft.com/v1.0',
            'expected_application_permission' => 'Mail.Send',
        ]);
    }

    private function notification(): DispatchNotification
    {
        return new DispatchNotification(
            recipients: ['customer@example.com'],
            subject: 'Dispatch load LOAD-100',
            htmlBody: '<p>Load summary</p>',
            idempotencyKey: str_repeat('a', 64),
        );
    }
}
