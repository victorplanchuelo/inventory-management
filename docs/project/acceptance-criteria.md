# Criterios de aceptación — StockFlow

Los criterios siguientes describen resultados observables. Deben convertirse en tests automatizados donde sea posible.

## Pedidos y reservas

### AC-01 — Pedido con stock suficiente
**Dado** que todos los productos tienen stock disponible suficiente  
**Cuando** se crea un pedido con esas líneas  
**Entonces** el pedido queda confirmado y se registra una reserva por cada línea.

### AC-02 — Stock insuficiente en una línea
**Dado** que una línea del pedido solicita más unidades de las disponibles  
**Cuando** se intenta crear/confirmar el pedido  
**Entonces** la operación falla con un error de negocio claro, el pedido no queda confirmado y no persiste ninguna reserva parcial.

### AC-03 — Pedido vacío
**Cuando** se intenta crear un pedido sin líneas  
**Entonces** el dominio rechaza la operación.

### AC-04 — Cantidad inválida
**Cuando** una línea tiene cantidad cero o negativa  
**Entonces** se rechaza antes de persistir cambios.

### AC-05 — Cancelación válida
**Dado** un pedido cancelable con reservas activas  
**Cuando** se cancela  
**Entonces** el pedido pasa al estado cancelado y las reservas se liberan una sola vez.

### AC-06 — Cancelación repetida
**Dado** un pedido ya cancelado  
**Cuando** se solicita cancelarlo de nuevo  
**Entonces** el comportamiento sigue la política explícita del dominio y nunca duplica la liberación de stock.

## Concurrencia y persistencia

### AC-07 — Dos pedidos compiten por las últimas unidades
**Dado** que el stock disponible no cubre ambos pedidos  
**Cuando** ambos intentan reservar concurrentemente  
**Entonces** como máximo se confirma el pedido que cabe en el stock y el inventario nunca queda negativo.

### AC-08 — Orden determinista de locks
Las líneas/productos se bloquean en un orden estable para reducir el riesgo de deadlocks.

### AC-09 — Rollback
Si falla cualquier escritura de la operación transaccional, se revierten pedido, líneas, reservas y mensajes Outbox asociados.

## Outbox y RabbitMQ

### AC-10 — Registro Outbox atómico
El cambio de negocio y su mensaje Outbox se confirman o revierten juntos.

### AC-11 — RabbitMQ no disponible
Si RabbitMQ está caído después del commit, el pedido confirmado y el mensaje Outbox permanecen persistidos para reintento posterior.

### AC-12 — Entrega duplicada
Si un consumidor recibe dos veces el mismo mensaje, no repite el efecto de negocio.

### AC-13 — Fallo del consumidor
Los errores transitorios se reintentan según configuración. Los fallos permanentes terminan aislados y son observables.

## Calidad y API

### AC-14 — Dominio independiente
Los tests unitarios de dominio se ejecutan sin levantar Laravel, MySQL o RabbitMQ.

### AC-15 — Contrato HTTP
Las rutas devuelven códigos y estructuras de respuesta documentados y validan las entradas.

### AC-16 — Calidad automatizada
La CI ejecuta tests, análisis estático y chequeos de estilo configurados en el repositorio.

### AC-17 — Instalación reproducible
Una persona que clone el proyecto puede levantar dependencias, configurar entorno, ejecutar migraciones y correr tests siguiendo la documentación.
