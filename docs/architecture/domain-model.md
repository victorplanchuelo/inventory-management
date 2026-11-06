# Modelo de dominio — StockFlow

Este documento describe un modelo inicial. Los nombres, límites y reglas deben contrastarse con el código y ajustarse mediante tests y ADRs antes de consolidarlos.

## Módulo Orders

### Aggregate: Order
Responsabilidad: mantener la consistencia del pedido y sus transiciones de estado.

Conceptos candidatos:
- `OrderId`: identidad del pedido.
- `OrderItem`: línea del pedido, con `ProductId` y `Quantity`.
- `OrderStatus`: estado explícito, por ejemplo `Pending`, `Confirmed`, `Cancelled`.
- `OrderRepository`: puerto de persistencia definido en Application o en la ubicación que siga la convención existente.

Invariantes candidatas:
- Un pedido contiene al menos una línea.
- Las cantidades son positivas.
- No se confirma un pedido ya cancelado.
- No se cancela un pedido en un estado no cancelable.
- No se confirma un pedido si no se ha completado la reserva de todas sus líneas.

El agregado Order no debe consultar directamente el inventario mediante Eloquent ni conocer RabbitMQ. La coordinación entre agregados pertenece al caso de uso.

Eventos candidatos:
- `OrderCreated`
- `OrderConfirmed`
- `OrderCancelled`

Los eventos de integración deben contener identificadores y datos mínimos necesarios, nunca contraseñas ni secretos.

## Módulo Inventory

### Aggregate: StockItem
Responsabilidad: proteger la cantidad y disponibilidad de un producto en inventario.

Conceptos candidatos:
- `StockItemId`
- `ProductId`
- `Quantity`
- `StockReservation` o entidad/registro de reserva con identidad propia.
- `StockRepository`

Invariantes candidatas:
- El stock disponible nunca es negativo.
- Una reserva no supera el disponible.
- Una reserva solo se libera una vez.
- Añadir stock requiere una cantidad positiva.
- Las operaciones concurrentes no pueden sobre-reservar unidades.

Definición conceptual:

`available = on_hand - reserved`

Acordar si `reserved` se almacena o se deriva de reservas activas. Evitar duplicar contadores sin una estrategia clara de consistencia.

Eventos candidatos:
- `StockReserved`
- `StockReleased`
- `StockAdded`

## Coordinación entre módulos

El caso de uso de creación/confirmación de pedido coordina Orders e Inventory. La decisión de reservar debe ejecutarse en la misma transacción MySQL que persiste el pedido.

No se recomienda que `Order` mantenga una referencia directa al agregado `StockItem`, ni que Inventory dependa del agregado Order.

## Identificadores y Value Objects

- Preferir tipos explícitos para IDs y cantidades.
- No acoplar IDs de dominio a `int` si la estrategia elegida utiliza UUID/ULID.
- Validar primitivas al construir Value Objects.
- `fromPrimitives()` debe reconstruir Value Objects explícitamente.
- `toPrimitives()` debe devolver primitivas, no objetos Value Object.
- Evitar exponer internamente propiedades mutables.

## Eventos de dominio e integración

Distinguir:
- Evento de dominio: hecho relevante ocurrido dentro del modelo.
- Mensaje de integración: contrato publicado a otros módulos/procesos.

No todos los eventos de dominio tienen que salir a RabbitMQ. La traducción y publicación deben estar controladas por Application/Infrastructure y respetar los contratos existentes.
