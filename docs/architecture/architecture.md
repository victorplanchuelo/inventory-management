# Arquitectura — StockFlow

## Estilo arquitectónico

StockFlow evoluciona como **monolito modular** con separación hexagonal por módulo. Los módulos comparten proceso y despliegue, pero mantienen límites explícitos.

Módulos iniciales:
- `Orders`: ciclo de vida de pedidos y coordinación de sus líneas.
- `Inventory`: cantidades, disponibilidad y reservas.
- `Shared`: capacidades transversales ya existentes, que deben inspeccionarse antes de reutilizarse.

La estructura propuesta no debe imponerse mecánicamente sobre el repositorio. El autoload PSR-4 conocido es `Manager\\` → `src/`; respeta los namespaces existentes.

## Capas

### Domain
Contiene agregados, entidades, value objects, reglas, invariantes y eventos de dominio.
- No depende de Laravel, Eloquent, HTTP, Symfony Messenger ni RabbitMQ.
- No conoce tablas ni detalles de serialización.
- Expone comportamientos, no setters indiscriminados.

### Application
Contiene casos de uso, handlers, puertos y coordinación.
- Orquesta agregados y puertos.
- Define la frontera transaccional mediante un puerto apropiado.
- No contiene consultas Eloquent ni lógica HTTP.
- Puede reutilizar buses existentes si sus contratos son adecuados.

### Infrastructure
Implementa puertos mediante adaptadores:
- Persistencia MySQL/Eloquent.
- Unit of Work/transacciones.
- Outbox y publisher.
- Adaptador Symfony Messenger/RabbitMQ.
- Broadcasting/Reverb, si se implementa.

### Entrada HTTP
Controladores, Form Requests, serialización de respuestas y rutas son adaptadores de entrada. No deben residir en Domain ni Application. Si el repositorio tiene controladores Laravel en `src/Api/User/Infrastructure`, planifica su migración de forma acotada y compatible; no hagas un traslado masivo sin revisar rutas y consumidores.

## Estructura objetivo orientativa

```text
src/
├── Shared/
│   ├── Domain/
│   └── Application/
├── Orders/
│   ├── Domain/
│   └── Application/
└── Inventory/
    ├── Domain/
    └── Application/

app/
├── Http/
│   └── Controllers/
├── Infrastructure/
│   ├── Persistence/
│   │   └── Eloquent/
│   ├── Messaging/
│   │   ├── Outbox/
│   │   └── RabbitMQ/
│   └── Broadcasting/
└── Providers/

tests/
├── Unit/
├── Integration/
└── Feature/
```

Es una dirección de organización, no una afirmación sobre el árbol actual ni una instrucción para mover todos los archivos existentes.

## Flujo principal: crear y confirmar pedido

1. El adaptador HTTP valida el formato de la solicitud.
2. El caso de uso crea el agregado de pedido y coordina las líneas.
3. Se inicia una transacción MySQL.
4. Se bloquean las filas de inventario necesarias con `SELECT ... FOR UPDATE`, en orden determinista.
5. Se comprueba y reserva la totalidad del stock.
6. Se confirma el pedido y se persisten las reservas.
7. Se registran los eventos destinados a integración en Outbox dentro de la misma transacción.
8. Se confirma la transacción.
9. Un publisher independiente lee mensajes pendientes de Outbox y los envía a RabbitMQ.
10. Los consumidores procesan los mensajes de forma idempotente.

No se debe usar un mensaje asíncrono para decidir si el pedido puede reservar stock: esa decisión debe ser consistente y síncrona con la transacción de negocio.

## Consistencia y fallos

- Fallo antes del commit: rollback de pedido, reservas y Outbox.
- RabbitMQ no disponible después del commit: el mensaje permanece en Outbox y se reintenta.
- Publisher cae después de publicar pero antes de marcar el mensaje como publicado: puede haber duplicado; el consumidor debe ser idempotente.
- Consumer falla: reintento limitado y aislamiento de mensajes no procesables.
- No se promete exactly-once. Se diseña para entrega al menos una vez y efectos idempotentes.

## Shared y compatibilidad

El repositorio ya contiene buses y contratos de eventos en `src/Shared`. Antes de crear nuevos:
1. Lee interfaces, implementaciones y configuración de Messenger.
2. Determina si los buses son puertos de aplicación o abstracciones acopladas a infraestructura.
3. Comprueba cómo se serializan y transportan los eventos actuales.
4. Evita romper el módulo User.
5. Si hace falta mejorar Shared, hazlo mediante cambios pequeños y compatibles, con tests.

El `DomainEvent` existente se ha descrito como ligado a `aggregateId:int` y fecha string. No asumas que sirve para todos los nuevos identificadores o eventos; comprueba su contrato real y decide si evolucionarlo o introducir un contrato neutral compatible.

## Calidad

- Dominio: PHPUnit unitario.
- Aplicación: tests con repositorios y Unit of Work en memoria.
- Infraestructura: integración con MySQL/RabbitMQ.
- API: tests Feature/HTTP.
- Análisis estático y estilo: usar herramientas realmente configuradas en Composer.
