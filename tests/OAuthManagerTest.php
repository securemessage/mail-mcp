<?php
/**
 * SecureMessage Mail MCP Server — OAuthManager Tests
 */

use PHPUnit\Framework\TestCase;
use Mail\OAuthManager;
use EnchiladaOAuth\EnchiladaOauth3LOClient;

class OAuthManagerTest extends TestCase
{
	private string $tempDir;

	protected function setUp(): void
	{
		$this->tempDir = sys_get_temp_dir() . '/mail-mcp-test-tokens-' . getmypid();
		@mkdir($this->tempDir, 0700, true);
	}

	protected function tearDown(): void
	{
		// Clean up temp token files
		$files = glob($this->tempDir . '/*');
		foreach ($files as $file) {
			unlink($file);
		}
		@rmdir($this->tempDir);
	}

	public function testTokenFilePathUsesInstanceName(): void
	{
		$manager = new OAuthManager($this->tempDir);
		$this->assertEquals(
			$this->tempDir . DIRECTORY_SEPARATOR . 'gmail.json',
			$manager->getTokenFile('gmail')
		);
	}

	public function testHasTokensReturnsFalseWhenNoTokenFile(): void
	{
		$manager = new OAuthManager($this->tempDir);
		$config = [
			'oauth_token_url' => 'https://oauth2.googleapis.com/token',
			'oauth_authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
			'oauth_client_id' => 'test-client-id',
			'oauth_client_secret' => 'test-secret',
			'oauth_scopes' => 'https://mail.google.com/',
		];

		$this->assertFalse($manager->hasTokens('nonexistent', $config));
	}

	public function testHasTokensReturnsTrueWithRefreshToken(): void
	{
		$manager = new OAuthManager($this->tempDir);
		$config = [
			'oauth_token_url' => 'https://oauth2.googleapis.com/token',
			'oauth_authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
			'oauth_client_id' => 'test-client-id',
			'oauth_client_secret' => 'test-secret',
			'oauth_scopes' => 'https://mail.google.com/',
		];

		// Pre-populate a token file with a refresh token
		$tokenData = [
			'access_token' => 'expired-token',
			'refresh_token' => 'valid-refresh-token',
			'expires_at' => time() - 3600, // expired
			'granted_scope' => 'https://mail.google.com/',
		];
		file_put_contents(
			$this->tempDir . '/test-gmail.json',
			json_encode($tokenData, JSON_PRETTY_PRINT)
		);

		$this->assertTrue($manager->hasTokens('test-gmail', $config));
	}

	public function testClearTokensRemovesFile(): void
	{
		$manager = new OAuthManager($this->tempDir);

		$tokenFile = $this->tempDir . '/clear-test.json';
		file_put_contents($tokenFile, json_encode(['refresh_token' => 'test']));
		$this->assertFileExists($tokenFile);

		$manager->clearTokens('clear-test');
		$this->assertFileDoesNotExist($tokenFile);
	}

	public function testGetAccessTokenReturnsNullWhenNoTokens(): void
	{
		$manager = new OAuthManager($this->tempDir);
		$config = [
			'oauth_token_url' => 'https://oauth2.googleapis.com/token',
			'oauth_authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
			'oauth_client_id' => 'test-client-id',
			'oauth_client_secret' => 'test-secret',
			'oauth_scopes' => 'https://mail.google.com/',
		];

		$result = $manager->getAccessToken('no-tokens', $config);
		$this->assertNull($result);
	}

	public function testGetAccessTokenReturnsValidCachedToken(): void
	{
		$manager = new OAuthManager($this->tempDir);
		$config = [
			'oauth_token_url' => 'https://oauth2.googleapis.com/token',
			'oauth_authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
			'oauth_client_id' => 'test-client-id',
			'oauth_client_secret' => 'test-secret',
			'oauth_scopes' => 'https://mail.google.com/',
		];

		// Pre-populate with a valid (not expired) token
		$tokenData = [
			'access_token' => 'valid-access-token',
			'refresh_token' => 'valid-refresh-token',
			'expires_at' => time() + 3600, // 1 hour from now
			'granted_scope' => 'https://mail.google.com/',
		];
		file_put_contents(
			$this->tempDir . '/cached-test.json',
			json_encode($tokenData, JSON_PRETTY_PRINT)
		);

		$result = $manager->getAccessToken('cached-test', $config);
		$this->assertEquals('valid-access-token', $result);
	}

	public function testDefaultTokenDirUsesHomeConfig(): void
	{
		$home = getenv('HOME') ?: sys_get_temp_dir();
		$manager = new OAuthManager();
		$expected = $home . '/.config/mail-mcp/tokens' . DIRECTORY_SEPARATOR . 'test.json';
		$this->assertEquals($expected, $manager->getTokenFile('test'));
	}

	public function testMissingTokenUrlThrowsException(): void
	{
		$manager = new OAuthManager($this->tempDir);
		$config = [
			'oauth_authorize_url' => 'https://example.com/auth',
			'oauth_client_id' => 'test',
			'oauth_client_secret' => 'test',
		];

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Missing oauth_token_url');
		$manager->getOAuthClient('bad-config', $config);
	}
}
