# Requisitos — StockFlow

## Requisitos funcionales

### RF-01 — Crear pedido
La API permitirá crear un pedido con al menos una línea. Cada línea identifica un producto y una cantidad positiva.

### RF-02 — Confirmación atómica
Un pedido solo podrá confirmarse si todas sus líneas pueden reservarse. La operación completa debe ser atómica: si falla una línea, no quedará ninguna reserva parcial ni un pedido confirmado.

### RF-03 — Consultar pedido
La API permitirá consultar el identificador, estado y líneas de un pedido.

### RF-04 — Cancelar pedido
La API permitirá solicitar la cancelación de un pedido en un estado cancelable. Si existen reservas activas, se liberarán de forma consistente.

### RF-05 — Registrar inventario
La API permitirá registrar la cantidad inicial de inventario para un producto, evitando estados inválidos y duplicidades no contempladas.

### RF-06 — Añadir inventario
La API permitirá incrementar el stock de un producto existente con una cantidad positiva.

### RF-07 — Consultar inventario
La API permitirá consultar cantidades disponibles, reservadas y, si el modelo lo contempla, totales.

### RF-08 — Publicar eventos
Los eventos que deban notificarse a otros componentes se registrarán en Outbox dentro de la misma transacción que el cambio de negocio.

### RF-09 — Consumir eventos
Los mensajes publicados en RabbitMQ se procesarán con reintentos e idempotencia. Los fallos permanentes deberán poder aislarse mediante una dead-letter queue o mecanismo equivalente.

## Requisitos no funcionales

### RNF-01 — Consistencia
La persistencia de pedido, reservas y registros Outbox debe mantener consistencia transaccional en MySQL.

### RNF-02 — Concurrencia
La reserva debe protegerse frente a carreras concurrentes mediante bloqueo de filas y una estrategia de adquisición de locks determinista.

### RNF-03 — Separación de responsabilidades
El dominio y los casos de uso no dependerán de Laravel, Eloquent, HTTP ni RabbitMQ.

### RNF-04 — Testabilidad
Las reglas de dominio se probarán con tests unitarios rápidos. Los adaptadores se probarán mediante tests de integración.

### RNF-05 — Idempotencia
La repetición de un mensaje no debe repetir efectos de negocio no idempotentes.

### RNF-06 — Compatibilidad
El código nuevo debe ser compatible con PHP 8.3 y con las versiones efectivamente instaladas de Laravel y librerías.

### RNF-07 — Seguridad
No se incluirán secretos ni credenciales en logs, eventos o respuestas. Las entradas externas se validarán.

### RNF-08 — Operabilidad local
El entorno de desarrollo debe documentar los servicios requeridos, variables de entorno, migraciones, tests y workers.

## Reglas de negocio iniciales

- Un pedido debe contener al menos una línea.
- Las cantidades solicitadas deben ser enteros positivos.
- No se puede reservar más stock del disponible.
- No se puede confirmar parcialmente un pedido.
- Las transiciones de estado deben estar explícitamente modeladas.
- Una reserva solo puede liberarse una vez.
- Una cancelación no debe liberar stock dos veces.
- Los identificadores de dominio no deben depender de tipos concretos de base de datos.
- Un evento no debe transportar secretos ni información que el consumidor no necesite.

## Decisiones pendientes que requieren validación

- Si un pedido se crea directamente como `pending` o se confirma en el mismo caso de uso.
- Si se permite modificar pedidos ya creados.
- Política de expiración de reservas.
- Si se permite inventario negativo por ajustes administrativos (por defecto, no).
- Política de idempotency key para solicitudes HTTP.
- Formato exacto de errores y códigos HTTP.
- Autenticación y autorización.
- Política de cancelación tras publicación de eventos.
