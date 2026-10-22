<?php
/**
 * Tests for ConnectionTools OAuth single-flight behavior: repeated
 * mail_connect calls for an instance with a pending OAuth flow must
 * resume the same flow instead of minting a new callback listener and
 * a new browser tab for every call.
 */

use PHPUnit\Framework\TestCase;
use Enchilada\Tortilla\StdioTransport;
use Mail\InstanceManager;

class RecordingTransport extends StdioTransport
{
    public array $logMessages = [];
    public array $streams = [];

    public function __construct()
    {
        // Intentionally skip parent construction: record-only test double.
    }

    public function addStream($stream, callable $callback): void
    {
        $this->streams[] = $stream;
    }

    public function removeStream($stream): void
    {
        $this->streams = array_filter($this->streams, fn($s) => $s !== $stream);
    }

    public function sendLogMessage(string $level, string $message, string $logger = ''): void
    {
        $this->logMessages[] = [$level, $message];
    }
}

class OAuthSingleFlightTest extends TestCase
{
    protected function setUp(): void
    {
        // Never open a real browser during tests
        putenv('BROWSER=');
        putenv('DISPLAY=');
    }

    protected function tearDown(): void
    {
        $prop = new ReflectionProperty(ConnectionTools::class, 'pendingFlows');
        $prop->setAccessible(true);
        foreach ($prop->getValue() as $flow) {
            $flow['server']->close();
        }
        $prop->setValue(null, []);
    }

    private function instanceConfig(): array
    {
        return [
            'sftest' => [
                'auth_type' => 'xoauth2',
                'imap_host' => 'imap.unused.invalid',
                'smtp_host' => 'smtp.unused.invalid',
                'imap_port' => 993,
                'smtp_port' => 465,
                'imap_encryption' => 'ssl',
                'smtp_encryption' => 'ssl',
                'username' => 'test@example.com',
                'oauth_token_url' => 'https://oauth2.googleapis.com/token',
                'oauth_authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'oauth_client_id' => 'test-client-id',
                'oauth_client_secret' => 'test-secret',
                'oauth_scopes' => 'https://mail.google.com/',
            ],
        ];
    }

    public function testRepeatedMailConnectResumesPendingFlow(): void
    {
        $manager = new InstanceManager($this->instanceConfig(), 'sftest');
        $tools = new ConnectionTools($manager);
        $transport = new RecordingTransport();
        $tools->setTransport($transport);

        $first = $tools->mail_connect('sftest');
        $this->assertSame('authorization_required', $first['oauth']);
        $this->assertArrayHasKey('authorize_url', $first);
        $this->assertCount(1, $transport->streams);

        $second = $tools->mail_connect('sftest');
        $this->assertSame('authorization_pending', $second['oauth']);
        $this->assertSame($first['authorize_url'], $second['authorize_url']);
        $this->assertStringContainsString('already in progress', $second['message']);
        // No second listener and no second browser attempt
        $this->assertCount(1, $transport->streams);
        $warnings = array_filter($transport->logMessages, fn($m) => $m[0] === 'warning');
        $this->assertCount(1, $warnings);
    }

    public function testPerInstanceTracking(): void
    {
        $configs = $this->instanceConfig();
        $configs['other'] = $configs['sftest'];
        $configs['other']['username'] = 'other@example.com';

        $manager = new InstanceManager($configs, 'sftest');
        $tools = new ConnectionTools($manager);
        $tools->setTransport(new RecordingTransport());

        $tools->mail_connect('sftest');
        $result = $tools->mail_connect('other');

        $this->assertSame('authorization_required', $result['oauth']);
    }
}
