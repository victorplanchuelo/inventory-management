<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Application\Inbox;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Manager\Shared\Application\Inbox\InboxConsumerValidator;
use Manager\Shared\Application\Inbox\InboxProcessingResult;
use Manager\Shared\Application\Inbox\InboxProcessor;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use Manager\Shared\Infrastructure\Database\MySqlTransactionalSession;
use Manager\Shared\Infrastructure\Inbox\MySqlInboxStore;
use Manager\Shared\Infrastructure\Inbox\MySqlInboxStoreFactory;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Shared\Infrastructure\Inbox\EphemeralInboxTable;
use Tests\Shared\Infrastructure\Inbox\FixedInboxStoreFactory;
use Tests\Shared\Infrastructure\Inbox\SignalingInboxStore;
use Tests\Shared\Infrastructure\IntegrationEvent\IntegrationEventFixture;
use Tests\Shared\Infrastructure\IntegrationEvent\TestIntegrationEventSubscriber;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;
use Throwable;

final class InboxProcessorDuplicateRaceTest extends InfrastructureTestCase
{
	private const CONSUMER = 'test.duplicate_race';

	private Connection $connection;
	private EphemeralInboxTable $tables;

	protected function setUp(): void
	{
		parent::setUp();

		$this->connection = DB::connection();
		$this->tables = new EphemeralInboxTable($this->connection);
		$this->tables->create();
		$this->tables->createBusinessTable();
	}

	protected function tearDown(): void
	{
		$this->tables->drop();

		parent::tearDown();
	}

	#[Test]
	public function it_should_let_exactly_one_worker_claim_and_the_loser_return_duplicate_after_rollback(): void
	{
		if (!function_exists('pcntl_fork')) {
			$this->markTestSkipped('pcntl is required for the duplicate-race test.');
		}

		$event = IntegrationEventFixture::envelope();
		[$commandParent, $commandChild] = $this->socketPair();
		[$ackParent, $ackChild] = $this->socketPair();
		[$resultParent, $resultChild] = $this->socketPair();

		$pid = pcntl_fork();

		if ($pid === -1) {
			$this->fail('The duplicate-race worker could not be forked.');
		}

		if ($pid === 0) {
			fclose($commandParent);
			fclose($ackParent);
			fclose($resultParent);
			$this->runLoserWorker($event, $commandChild, $ackChild, $resultChild);
		}

		fclose($commandChild);
		fclose($ackChild);
		fclose($resultChild);

		$subscriber = new TestIntegrationEventSubscriber(
			self::CONSUMER,
			[IntegrationEventFixture::EVENT_NAME => ['1']],
			function (IntegrationEventEnvelope $event) use ($commandParent, $ackParent): void {
				$this->connection->table($this->tables->businessTable)->insert([
					'id' => $event->eventId,
					'note' => 'winner effect',
				]);

				fwrite($commandParent, 'go');
				fflush($commandParent);

				stream_set_timeout($ackParent, 10);
				$signal = fread($ackParent, 8);

				if ($signal !== 'claiming') {
					throw new RuntimeException('The duplicate-race worker did not reach the claim.');
				}

				usleep(200000);
			}
		);

		$processor = $this->processor();

		try {
			$parentResult = $processor->process($event, $subscriber);
			stream_set_timeout($resultParent, 10);
			$childPayload = stream_get_contents($resultParent);
		} finally {
			fclose($commandParent);
			fclose($ackParent);
			fclose($resultParent);

			$waited = pcntl_waitpid($pid, $status, WNOHANG);

			if ($waited === 0) {
				posix_kill($pid, SIGKILL);
				pcntl_waitpid($pid, $status);
			}
		}

		$this->assertSame(InboxProcessingResult::PROCESSED, $parentResult);
		$this->assertIsString($childPayload);

		$child = json_decode($childPayload, true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame(InboxProcessingResult::DUPLICATE->value, $child['result']);
		$this->assertSame(0, $child['transaction_level']);
		$this->assertSame(1, $this->connection->table($this->tables->businessTable)->count());

		$row = $this->connection->table($this->tables->inboxTable)->first();

		$this->assertNotNull($row);
		$this->assertNotNull($row->processed_at);
		$this->assertSame('processed', $row->outcome);
		$this->assertSame(1, $this->connection->table($this->tables->inboxTable)->count());
	}

	/**
	 * @param resource $command
	 * @param resource $ack
	 * @param resource $result
	 */
	private function runLoserWorker(
		IntegrationEventEnvelope $event,
		mixed $command,
		mixed $ack,
		mixed $result
	): never {
		try {
			DB::purge();
			DB::reconnect();

			$connection = DB::connection();
			$store = new SignalingInboxStore(new MySqlInboxStore($connection, $this->tables->inboxTable), $ack);
			$processor = new InboxProcessor(
				new MySqlTransactionalSession($connection),
				new FixedInboxStoreFactory($store),
				new InboxConsumerValidator(['test' => $this->tables->inboxTable])
			);

			$start = fread($command, 2);

			if ($start !== 'go') {
				throw new RuntimeException('The winner did not signal the loser.');
			}

			$processed = $processor->process(
				$event,
				new TestIntegrationEventSubscriber(
					self::CONSUMER,
					[IntegrationEventFixture::EVENT_NAME => ['1']],
					static function (): void {}
				)
			);

			$payload = [
				'result' => $processed->value,
				'transaction_level' => $connection->transactionLevel(),
			];
		} catch (Throwable $error) {
			$payload = [
				'error' => $error::class,
				'message' => $error->getMessage(),
				'transaction_level' => DB::connection()->transactionLevel(),
			];
		}

		fwrite($result, json_encode($payload, JSON_THROW_ON_ERROR));
		fflush($result);
		fclose($result);
		fclose($command);
		fclose($ack);

		exit(0);
	}

	private function processor(): InboxProcessor
	{
		return new InboxProcessor(
			new MySqlTransactionalSession($this->connection),
			new MySqlInboxStoreFactory($this->connection, ['test' => $this->tables->inboxTable]),
			new InboxConsumerValidator(['test' => $this->tables->inboxTable])
		);
	}

	/**
	 * @return array{resource, resource}
	 */
	private function socketPair(): array
	{
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

		if ($pair === false) {
			$this->fail('The duplicate-race socket pair could not be created.');
		}

		return $pair;
	}
}
