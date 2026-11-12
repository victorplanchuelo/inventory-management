<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

final readonly class OutboxErrorSanitizer
{
	public const REDACTED = '[REDACTED]';

	/** @var list<array{pattern: string, replacement: string}> */
	private const RULES = [
		// PEM private keys, including multi-line blocks.
		[
			'pattern' => '~-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----~s',
			'replacement' => self::REDACTED,
		],
		// Authorization and proxy-authorization header values.
		[
			'pattern' => '~((?<![A-Za-z0-9_])(?:proxy-)?authorization\s*[:=]\s*)(?:bearer|basic|digest|negotiate|token)\s+[^\r\n,;]+~i',
			'replacement' => '$1' . self::REDACTED,
		],
		// Credentials embedded in URI userinfo: scheme://user:password@host.
		[
			'pattern' => '~(://)[^/\s?#]*@~',
			'replacement' => '$1' . self::REDACTED . '@',
		],
		// JSON Web Tokens.
		[
			'pattern' => '~eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+~',
			'replacement' => self::REDACTED,
		],
		// Common provider token formats.
		[
			'pattern' => '~(?<![A-Za-z0-9])(?:AKIA|ASIA)[0-9A-Z]{16}(?![A-Za-z0-9])~',
			'replacement' => self::REDACTED,
		],
		[
			'pattern' => '~(?<![A-Za-z0-9])(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{20,}~',
			'replacement' => self::REDACTED,
		],
		[
			'pattern' => '~(?<![A-Za-z0-9])github_pat_[A-Za-z0-9_]{20,}~',
			'replacement' => self::REDACTED,
		],
		[
			'pattern' => '~(?<![A-Za-z0-9])xox[baprs]-[A-Za-z0-9-]{10,}~',
			'replacement' => self::REDACTED,
		],
		[
			'pattern' => '~(?<![A-Za-z0-9])sk_(?:live|test)_[A-Za-z0-9]{16,}~',
			'replacement' => self::REDACTED,
		],
		[
			'pattern' => '~(?<![A-Za-z0-9])AIza[0-9A-Za-z_-]{35}~',
			'replacement' => self::REDACTED,
		],
		// Bearer tokens outside an authorization header.
		[
			'pattern' => '~(?<![A-Za-z0-9])(bearer)\s+[A-Za-z0-9._\~+/=-]{16,}~i',
			'replacement' => '$1 ' . self::REDACTED,
		],
		// Quoted key/value pairs, including JSON and PHP array syntax.
		[
			'pattern' => '~((?<![A-Za-z0-9_])(?:[A-Za-z0-9]+[_-])*(?:password|passwd|pass|pwd|secret|api[_-]?key|access[_-]?key(?:[_-]?id)?|secret[_-]?access[_-]?key|private[_-]?key|authorization(?:[_-]?code)?|credentials?|user(?:name)?|cookie|signature|sig|token)(?![A-Za-z0-9_])[\\\\]?["\']?\s*(?:=>|=|:)(?!>)\s*)([\\\\]?["\'])(.*?)(\2)~i',
			'replacement' => '$1$2' . self::REDACTED . '$4',
		],
		// Unquoted key/value pairs.
		[
			'pattern' => '~((?<![A-Za-z0-9_])(?:[A-Za-z0-9]+[_-])*(?:password|passwd|pass|pwd|secret|api[_-]?key|access[_-]?key(?:[_-]?id)?|secret[_-]?access[_-]?key|private[_-]?key|authorization(?:[_-]?code)?|credentials?|user(?:name)?|cookie|signature|sig|token)(?![A-Za-z0-9_])[\\\\]?["\']?\s*(?:=>|=|:)(?!>)\s*)(?!\\\\?["\'])([^\s"\'`,;&})]+)~i',
			'replacement' => '$1' . self::REDACTED,
		],
	];

	public function sanitize(string $message): string
	{
		return $this->normalizeControlCharacters($this->redact($message));
	}

	private function redact(string $message): string
	{
		$sanitized = $message;

		foreach (self::RULES as $rule) {
			$result = preg_replace($rule['pattern'], $rule['replacement'], $sanitized);

			if ($result !== null) {
				$sanitized = $result;
			}
		}

		return $sanitized;
	}

	/**
	 * Collapse CR/LF, tabs and other non-printable controls so an exception
	 * message cannot forge additional lines in logs or console output.
	 */
	private function normalizeControlCharacters(string $message): string
	{
		$normalized = preg_replace('/\s*[\x00-\x1F\x7F]+\s*/', ' ', $message);

		if ($normalized === null) {
			$normalized = $message;
		}

		if (mb_check_encoding($normalized, 'UTF-8')) {
			$result = preg_replace('/[ \t]*[\x{0080}-\x{009F}\x{2028}\x{2029}]+[ \t]*/u', ' ', $normalized,);

			if ($result !== null) {
				$normalized = $result;
			}
		}

		return trim($normalized);
	}
}
