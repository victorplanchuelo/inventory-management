# StockFlow

<p align="center">
  <strong>Pedidos e inventario, con consistencia de extremo a extremo.</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white" alt="PHP 8.3">
  <img src="https://img.shields.io/badge/Laravel-11-FF2D20?logo=laravel&logoColor=white" alt="Laravel 11">
  <img src="https://img.shields.io/badge/MySQL-8-4479A1?logo=mysql&logoColor=white" alt="MySQL 8">
  <img src="https://img.shields.io/badge/RabbitMQ-configurado-FF6600?logo=rabbitmq&logoColor=white" alt="RabbitMQ configurado">
  <img src="https://img.shields.io/badge/Estado-en%20desarrollo-2F80ED" alt="En desarrollo">
</p>

StockFlow es una API backend en desarrollo para gestionar pedidos y disponibilidad de inventario. El proyecto busca ofrecer una base mantenible para crear pedidos, reservar existencias de forma segura y conservar la consistencia cuando varias operaciones compiten por el mismo stock.

Se construye como un **monolito modular**: una aplicación y un despliegue, organizados en módulos con límites claros. En esta etapa se están preparando la estructura técnica y las decisiones de arquitectura; los principales flujos de negocio todavía están planificados.

## Índice

- [Objetivo](#objetivo)
- [Estado del proyecto](#estado-del-proyecto)
- [Arquitectura](#arquitectura)
- [Tecnologías](#tecnologías)
- [Puesta en marcha local](#puesta-en-marcha-local)
- [Pruebas y calidad](#pruebas-y-calidad)
- [Estructura del repositorio](#estructura-del-repositorio)
- [Documentación](#documentación)
- [Próximos pasos](#próximos-pasos)

## Objetivo

El alcance previsto para StockFlow incluye:

- Crear y consultar pedidos con una o varias líneas.
- Confirmar un pedido solo cuando se puedan reservar todas las unidades solicitadas.
- Registrar existencias y consultar la disponibilidad del inventario.
- Evitar reservas parciales y proteger el stock ante operaciones concurrentes.
- Cancelar pedidos conforme a sus reglas de estado y liberar sus reservas de forma consistente.
- Publicar eventos de negocio mediante RabbitMQ.

Estas capacidades describen el objetivo del proyecto. No deben interpretarse como funcionalidades ya implementadas.

## Estado del proyecto

La base técnica está configurada con Laravel, Docker/Sail, MySQL y RabbitMQ. La arquitectura y los requisitos funcionales están documentados; los casos de uso de pedidos e inventario siguen pendientes de implementación.

| Área | Estado |
| --- | --- |
| Aplicación Laravel y entorno local con Sail | Configurados |
| MySQL y RabbitMQ en Docker Compose | Configurados |
| Arquitectura y requisitos del producto | Documentados |
| Gestión de pedidos | Planificada |
| Gestión de inventario y reservas | Planificada |
| Reserva atómica y protección frente a concurrencia | Diseñadas; implementación pendiente |
| Outbox transaccional y publicación de eventos de negocio | Diseñados; implementación pendiente |

## Arquitectura

La dirección arquitectónica es un **monolito modular con separación por capas**:

- **Domain:** reglas, invariantes y conceptos del negocio.
- **Application:** casos de uso y coordinación entre módulos.
- **Infrastructure:** persistencia, mensajería e integraciones.
- **HTTP:** rutas, validación de entrada y respuestas de la API.

Los módulos de negocio previstos son:

- **Orders:** ciclo de vida de los pedidos y sus líneas.
- **Inventory:** existencias, disponibilidad y reservas.
- **Shared:** capacidades técnicas compartidas.

El dominio debe permanecer independiente de Laravel, Eloquent, HTTP y RabbitMQ. Las decisiones y los motivos detrás de ellas se recogen en los documentos de arquitectura y en los ADR.

StockFlow no se plantea como una aplicación de microservicios ni incluye un frontend en su alcance inicial.

## Tecnologías

- PHP 8.3
- Laravel 11
- MySQL 8
- RabbitMQ
- Symfony Messenger
- Laravel Sail y Docker Compose
- PHPUnit 11
- Easy Coding Standard (ECS)
- PHPStan con Larastan

Las versiones y extensiones PHP requeridas están definidas en `composer.json` y en la configuración de Docker.

## Puesta en marcha local

### Requisitos

- Docker con Docker Compose
- WSL 2 o un entorno Linux
