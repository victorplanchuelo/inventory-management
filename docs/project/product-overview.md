# StockFlow — visión del producto

## Propósito

StockFlow es una API backend para gestionar pedidos y disponibilidad de inventario. Su objetivo es demostrar diseño de software mantenible en PHP/Laravel, modelado de dominio, arquitectura hexagonal, consistencia transaccional, mensajería asíncrona y pruebas automatizadas.

No se plantea como una plataforma de microservicios ni como un producto con frontend. Es un **monolito modular** con límites internos claros.

## Usuarios y necesidades

Los consumidores de la API necesitan:
- Crear pedidos con una o varias líneas.
- Saber si un pedido ha sido confirmado o rechazado por falta de stock.
- Consultar el estado y detalle de un pedido.
- Cancelar pedidos cuando la política de negocio lo permita.
- Dar de alta inventario y añadir unidades.
- Consultar disponibilidad.
- Recibir efectos derivados de los cambios mediante eventos asíncronos.

Los perfiles concretos de usuario, autenticación, autorización y multi-tenancy quedan fuera del alcance inicial salvo que el repositorio ya los exija.

## Alcance funcional inicial

### Pedidos
- Crear un pedido con una o más líneas.
- Confirmar el pedido solo si se puede reservar la totalidad del stock requerido.
- Consultar un pedido.
- Cancelar un pedido sujeto a sus reglas de estado y liberación de reservas.

### Inventario
- Registrar stock para un producto.
- Añadir unidades.
- Consultar cantidades disponibles y reservadas.
- Reservar y liberar stock mediante operaciones coordinadas con pedidos.

### Integración asíncrona
- Registrar eventos que deban publicarse en Outbox dentro de la transacción de negocio.
- Publicarlos en RabbitMQ después del commit.
- Procesarlos mediante consumidores idempotentes.
- Dejar preparada una extensión para broadcasting en tiempo real; no es requisito prioritario disponer de una interfaz visual.

## Fuera de alcance inicial

- Frontend o aplicación móvil.
- Microservicios desplegables de forma independiente.
- Event sourcing completo.
- CQRS distribuido o proyecciones complejas.
- Pagos, facturación, envíos y gestión de proveedores.
- Orquestación compleja de workflows.
- Kubernetes y observabilidad empresarial avanzada.
- Garantía de entrega exactly-once.

## Criterios de éxito

El proyecto se considera demostrable cuando:
1. Se puede arrancar localmente siguiendo el README del repositorio.
2. Se pueden ejecutar tests unitarios y de integración relevantes.
3. Un pedido con stock suficiente se confirma y reserva todas sus líneas.
4. Un pedido sin stock suficiente no deja reservas parciales ni pedido confirmado.
5. Las operaciones concurrentes no permiten reservar más unidades de las disponibles.
6. El evento de negocio se persiste en Outbox en la misma transacción.
7. El publisher entrega mensajes a RabbitMQ y el consumidor tolera duplicados.
8. El código de dominio y aplicación puede probarse sin depender de Laravel.
9. La arquitectura y sus compromisos se pueden explicar con diagramas y ADRs.

## Restricciones técnicas

- PHP 8.3 es la versión objetivo.
- MySQL es la base de datos transaccional existente.
- RabbitMQ es el broker asíncrono existente.
- Symfony Messenger es la integración de mensajería disponible en el repositorio.
- La versión de Laravel debe decidirse tras comprobar compatibilidad real. No actualizar automáticamente a Laravel 13.
