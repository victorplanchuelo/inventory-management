# ADR 001 — Monolito modular

- Estado: Aceptado como dirección inicial
- Fecha: 2026-11-05

## Contexto

El proyecto debe demostrar límites de dominio y arquitectura hexagonal sin añadir complejidad operativa innecesaria. El repositorio ya es una aplicación Laravel con infraestructura compartida.

## Decisión

Mantener un único despliegue Laravel y organizar el código en módulos `Orders` e `Inventory`, con capas Domain y Application. Los adaptadores de infraestructura y entrada HTTP permanecen fuera del dominio.

## Consecuencias

Positivas:
- Menor coste de despliegue y desarrollo.
- Transacciones MySQL locales para pedido e inventario.
- Límites internos testeables.
- Posibilidad de extraer servicios en el futuro si existe una necesidad real.

Negativas:
- Los límites modulares dependen de disciplina y revisión.
- Los módulos comparten proceso, base de datos y ciclo de despliegue.

## Alternativas descartadas

- Microservicios independientes: añaden coordinación distribuida y complejidad que no aporta valor al alcance inicial.
- Aplicación Laravel sin límites internos: facilita el acoplamiento entre HTTP, persistencia y reglas de negocio.
