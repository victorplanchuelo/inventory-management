<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

use DateTimeImmutable;
use DateTimeZone;
use Manager\Shared\Application\Inbox\Exception\DuplicateInboxClaim;
use Manager\Shared\Application\Inbox\Exception\InboxClaimNotVerified;
use Manager\Shared\Application\Inbox\Exception\MissingAggregatePredecessor;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventSubscriber;
use Manager\Shared\Application\Transaction\TransactionalSession;

final readonly class InboxProcessor
{
	public function __construct(
		private TransactionalSession $session,
		private InboxStoreFactory $storeFactory,
		private InboxConsumerValidator $consumerValidator,
	) {}

	public function process(
		IntegrationEventEnvelope $event,
		IntegrationEventSubscriber $subscriber
	): InboxProcessingResult {
		$consumer = $subscriber->consumerName();
		$this->consumerValidator->assertIsValid($consumer);
		$store = $this->storeFactory->forConsumer($consumer);

		if ($store->hasHandled($consumer, $event->eventId)) {
			return InboxProcessingResult::DUPLICATE;
		}

		try {
			return $this->session->execute(
				function () use ($event, $subscriber, $consumer, $store): InboxProcessingResult {
					if (!$store->claim($event, $consumer, $this->now())) {
						throw new DuplicateInboxClaim($consumer, $event->eventId);
					}

					$policy = $subscriber->orderingPolicy();
					$lastAppliedVersion = $policy instanceof NoOrderingAggregateVersionPolicy
						? null
						: $store->lastProcessedAggregateVersion($consumer, $event->aggregateType, $event->aggregateId);
					$decision = $policy->decide($event->aggregateVersion, $lastAppliedVersion);

					if ($decision === AggregateVersionDecision::STALE) {
						$store->markProcessed($consumer, $event->eventId, InboxOutcome::STALE, $this->now());

						return InboxProcessingResult::STALE;
					}

					if ($decision === AggregateVersionDecision::GAP) {
						throw MissingAggregatePredecessor::forEvent($consumer, $event, $lastAppliedVersion);
					}

					($subscriber)($event);

					$store->markProcessed($consumer, $event->eventId, InboxOutcome::PROCESSED, $this->now());

					return InboxProcessingResult::PROCESSED;
				}
			);
		} catch (DuplicateInboxClaim) {
			$record = $store->find($consumer, $event->eventId);

			if ($record !== null && $record->processedAt !== null && $record->outcome !== null) {
				return InboxProcessingResult::DUPLICATE;
			}

			throw InboxClaimNotVerified::forEvent($consumer, $event->eventId);
		}
	}

	private function now(): DateTimeImmutable
	{
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}
}
