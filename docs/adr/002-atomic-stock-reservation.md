# ADR 002 — Reserva atómica de inventario

- Estado: Aceptado como dirección inicial
- Fecha: 2026-11-05

## Contexto

Un pedido puede contener varias líneas. Confirmar solo algunas reservas dejaría el sistema en un estado inconsistente. Además, varios pedidos pueden competir por las últimas unidades.

## Decisión

La creación/confirmación del pedido reserva todas las líneas dentro de una única transacción MySQL. Las filas de inventario necesarias se bloquean con `SELECT ... FOR UPDATE` y en un orden determinista. Pedido, líneas, reservas y registros Outbox se confirman o revierten juntos.

La comprobación de disponibilidad es síncrona. RabbitMQ no participa en la decisión de si hay stock.

## Consecuencias

Positivas:
- No hay pedidos parcialmente reservados.
- MySQL protege la exclusión mutua entre transacciones.
- La persistencia de negocio y Outbox puede ser atómica.

Negativas:
- La transacción puede mantener locks durante más tiempo si se amplía indebidamente.
- Hay que gestionar deadlocks/reintentos y probar concurrencia en MySQL real.
- No se debe realizar I/O de red a RabbitMQ dentro de la transacción.

## Requisitos de implementación

- Ordenar IDs antes de bloquear.
- Mantener la transacción lo más corta posible.
- No hacer llamadas externas mientras se mantienen locks.
- Definir política de retry para deadlocks.
- Testear rollback cuando una línea no tiene stock.
- Testear dos transacciones que compiten por las últimas unidades.

## Alternativas descartadas

- Reservar cada línea en transacciones separadas: permite reservas parciales.
- Publicar un comando asíncrono a RabbitMQ para reservar: complica la respuesta al cliente y no ofrece atomicidad local entre pedido y stock.
