<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use Manager\Shared\Infrastructure\Outbox\OutboxErrorSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class OutboxErrorSanitizerTest extends UnitTestCase
{
	#[Test]
	#[DataProvider('secretMessages')]
	public function it_should_redact_secrets_from_error_messages(string $message, string $expected): void
	{
		$this->assertSame($expected, (new OutboxErrorSanitizer())->sanitize($message));
	}

	#[Test]
	#[DataProvider('controlCharacterMessages')]
	public function it_should_normalize_control_characters(string $message, string $expected): void
	{
		$this->assertSame($expected, (new OutboxErrorSanitizer())->sanitize($message));
	}

	#[Test]
	public function it_should_leave_non_sensitive_messages_untouched(): void
	{
		$message = 'OutboxPublicationException: The Outbox record '
			. '<5a7dbb7a-1c3f-4f2e-9f6c-6a1d0b0c9e01> could not be published. '
			. 'The tokenization process and passwordless authentication are unrelated.';

		$this->assertSame($message, (new OutboxErrorSanitizer())->sanitize($message));
	}

	#[Test]
	public function it_should_be_idempotent(): void
	{
		$sanitizer = new OutboxErrorSanitizer();
		$sanitized = $sanitizer->sanitize(
			'Connection amqp://guest:hunter2@rabbitmq:5672 failed; password=hunter2 token=abc123'
		);

		$this->assertSame($sanitized, $sanitizer->sanitize($sanitized));
	}

	#[Test]
	public function it_should_normalize_ascii_controls_in_invalid_utf8_messages(): void
	{
		$sanitizer = new OutboxErrorSanitizer();
		$sanitized = $sanitizer->sanitize("Connection \xFF failed\x00retrying\n");

		$this->assertSame("Connection \xFF failed retrying", $sanitized);
		$this->assertSame($sanitized, $sanitizer->sanitize($sanitized));
		$this->assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $sanitized));
	}

	#[Test]
	public function it_should_be_idempotent_after_control_character_normalization(): void
	{
		$sanitizer = new OutboxErrorSanitizer();
		$sanitized = $sanitizer->sanitize(
			"Connection amqp://guest:hunter2@rabbitmq failed\r\npassword=hunter2\tretrying\x00"
		);

		$this->assertSame($sanitized, $sanitizer->sanitize($sanitized));
		$this->assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $sanitized));
	}

	/** @return iterable<string, array{string, string}> */
	public static function controlCharacterMessages(): iterable
	{
		yield 'line feed' => [
			"Connection failed\nINFO: outbox.record.published\nERROR: Authentication successful",
			'Connection failed INFO: outbox.record.published ERROR: Authentication successful',
		];

		yield 'carriage return and line feed' => [
			"Connection failed\r\nINFO: outbox.record.published\r\nERROR: Authentication successful",
			'Connection failed INFO: outbox.record.published ERROR: Authentication successful',
		];

		yield 'tabs' => ["Connection\tfailed\tretrying", 'Connection failed retrying', ];

		yield 'non-printable controls' => ["Connection \x00 failed \x1B retrying \x7F", 'Connection failed retrying', ];

		yield 'unicode line separator' => [
			"Connection failed\u{2028}INFO: outbox.record.published",
			'Connection failed INFO: outbox.record.published',
		];

		yield 'unicode paragraph separator' => [
			"Connection failed\u{2029}INFO: outbox.record.published",
			'Connection failed INFO: outbox.record.published',
		];

		yield 'C1 control (NEL U+0085)' => [
			"Connection failed\u{0085}INFO: outbox.record.published",
			'Connection failed INFO: outbox.record.published',
		];

		yield 'credentials combined with line breaks' => [
			"Connection amqp://guest:hunter2@rabbitmq failed\npassword=hunter2\r\nAuthorization: Bearer abcdefghijklmnop",
			'Connection amqp://[REDACTED]@rabbitmq failed password=[REDACTED] Authorization: [REDACTED]',
		];
	}

	/** @return iterable<string, array{string, string}> */
	public static function secretMessages(): iterable
	{
		yield 'ordinary failure' => ['RabbitMQ confirmation timed out.', 'RabbitMQ confirmation timed out.', ];

		yield 'URI userinfo' => [
			'Connection failed: amqp://guest:guest@rabbitmq:5672/%2f',
			'Connection failed: amqp://[REDACTED]@rabbitmq:5672/%2f',
		];

		yield 'database DSN' => ['mysql://root:s3cr3t@db:3306/stockflow', 'mysql://[REDACTED]@db:3306/stockflow', ];

		yield 'plain assignments' => [
			'Connection options: password=hunter2 user=root',
			'Connection options: password=[REDACTED] user=[REDACTED]',
		];

		yield 'prefixed environment assignment' => ['RABBITMQ_PASSWORD=guest', 'RABBITMQ_PASSWORD=[REDACTED]', ];

		yield 'JSON object' => [
			'{"password":"hunter2","api_key":"abc123"}',
			'{"password":"[REDACTED]","api_key":"[REDACTED]"}',
		];

		yield 'escaped JSON object' => ['{\\"password\\":\\"hunter2\\"}', '{\\"password\\":\\"[REDACTED]\\"}', ];

		yield 'PHP array syntax' => [
			"['password' => 'hunter2', 'token' => 'abc123']",
			"['password' => '[REDACTED]', 'token' => '[REDACTED]']",
		];

		yield 'authorization header' => [
			'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.signature',
			'Authorization: [REDACTED]',
		];

		yield 'proxy authorization header' => [
			'Proxy-Authorization: Basic dXNlcjpwYXNz',
			'Proxy-Authorization: [REDACTED]',
		];

		yield 'JWT in free text' => [
			'Token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.signature rejected',
			'Token [REDACTED] rejected',
		];

		yield 'AWS access key' => ['AWS key AKIAIOSFODNN7EXAMPLE rejected', 'AWS key [REDACTED] rejected', ];

		yield 'private key block' => ["-----BEGIN PRIVATE KEY-----\nsecret\n-----END PRIVATE KEY-----", '[REDACTED]', ];

		yield 'query string parameters' => [
			'https://example.test/path?token=abc123&api_key=secret-key',
			'https://example.test/path?token=[REDACTED]&api_key=[REDACTED]',
		];

		yield 'multiple secrets and separators' => [
			'mysql:host=db;dbname=stockflow;user=root;password=secret',
			'mysql:host=db;dbname=stockflow;user=[REDACTED];password=[REDACTED]',
		];
	}
}
