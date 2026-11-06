# ADR 003 — Outbox transaccional y RabbitMQ

- Estado: Aceptado como dirección inicial
- Fecha: 2026-11-05

## Contexto

Publicar directamente en RabbitMQ durante una transacción MySQL crea una ventana de inconsistencia: la base de datos puede hacer rollback después de publicar, o el commit puede completarse aunque RabbitMQ no esté disponible.

El repositorio ya contempla RabbitMQ y Symfony Messenger, por lo que se deben aprovechar antes de añadir otro broker.

## Decisión

Persistir cada mensaje de integración en una tabla Outbox dentro de la misma transacción que el cambio de negocio. Un publisher independiente lee mensajes pendientes y los envía a RabbitMQ usando la integración existente con Symfony Messenger.

Contrato mínimo orientativo de Outbox:
- `id`
- `event_name`
- `payload`
- `occurred_at`
- `published_at` nullable
- `attempts`

La implementación puede necesitar `available_at`, `locked_at`, `last_error` o una estrategia de lease para coordinar publishers concurrentes. Definirlo tras estudiar el entorno real.

## Entrega e idempotencia

No se promete exactly-once. Puede haber duplicados si el publisher publica correctamente y cae antes de marcar el registro como publicado.

Cada mensaje debe tener un identificador estable. El consumidor registra o reconoce ese identificador de forma que una entrega repetida no repita el efecto de negocio.

Configurar:
- Reintentos para errores transitorios.
- Dead-letter queue o mecanismo equivalente para mensajes agotados.
- Logs con correlation/message ID, sin datos sensibles.
- Métricas o visibilidad de mensajes pendientes y antiguos.

## Consecuencias

Positivas:
- El cambio de negocio y la intención de publicar se guardan atómicamente.
- RabbitMQ puede estar temporalmente caído sin perder el evento.
- Se desacopla el commit de los efectos secundarios.

Negativas:
- Hay más componentes y estados operativos.
- La entrega es eventual.
- Es necesario gestionar duplicados, reintentos y mensajes atascados.

## Alternativas descartadas

- Publicación directa dentro de la transacción: no es atómica entre MySQL y RabbitMQ.
- Redis como segundo broker: duplica infraestructura sin necesidad.
- Exactly-once: no se considera una garantía realista extremo a extremo; se adopta entrega al menos una vez con consumidores idempotentes.
