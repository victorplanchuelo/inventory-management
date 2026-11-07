

# StockFlow — Product Overview

## Purpose

StockFlow is a backend API for managing orders and inventory availability. Its purpose is to demonstrate maintainable software design in PHP/Laravel, domain modeling, hexagonal architecture, transactional consistency, asynchronous messaging, and automated testing.

It is not intended to be a microservices platform or a product with a frontend. It is a **modular monolith** with clear internal boundaries.

## Users and Needs

API consumers need to:

- Create orders with one or more line items.
- Know whether an order has been confirmed or rejected due to insufficient stock.
- Retrieve an order's status and details.
- Cancel orders when permitted by business policy.
- Register inventory and add units.
- Check availability.
- Receive side effects derived from changes through asynchronous events.

Specific user profiles, authentication, authorization, and multi-tenancy are outside the initial scope unless the repository already requires them.

## Initial Functional Scope

### Orders

- Create an order with one or more line items.
- Confirm an order only if the entire required stock quantity can be reserved.
- Retrieve an order.
- Cancel an order subject to its status rules and reservation release.

### Inventory

- Register stock for a product.
- Add units.
- Retrieve available and reserved quantities.
- Reserve and release stock through operations coordinated with orders.

### Asynchronous Integration

- Record events that must be published to the Outbox within the business transaction.
- Publish them to RabbitMQ after the commit.
- Process them through idempotent consumers.
- Prepare for a future extension to real-time broadcasting; having a visual interface is not a priority requirement.

## Initial Out of Scope

- Frontend or mobile application.
- Independently deployable microservices.
- Full event sourcing.
- Distributed CQRS or complex projections.
- Payments, invoicing, shipping, and supplier management.
- Complex workflow orchestration.
- Kubernetes and advanced enterprise observability.
- Exactly-once delivery guarantee.

## Success Criteria

The project is considered demonstrable when:

1. It can be started locally by following the repository README.
2. Relevant unit and integration tests can be executed.
3. An order with sufficient stock is confirmed and all its line items are reserved.
4. An order with insufficient stock does not leave partial reservations or a confirmed order.
5. Concurrent operations do not allow more units to be reserved than are available.
6. The business event is persisted in the Outbox within the same transaction.
7. The publisher delivers messages to RabbitMQ and the consumer tolerates duplicates.
8. Domain and application code can be tested without depending on Laravel.
9. The architecture and its trade-offs can be explained using diagrams and ADRs.

## Technical Constraints

- PHP 8.3 is the target version.
- MySQL is the existing transactional database.
- RabbitMQ is the existing asynchronous broker.
- Symfony Messenger is the messaging integration available in the repository.
- The Laravel version must be decided after checking actual compatibility. Do not automatically upgrade to Laravel 13.

