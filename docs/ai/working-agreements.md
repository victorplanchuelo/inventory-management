# Acuerdos de trabajo con IA

## Objetivo

Usar asistentes de IA para acelerar investigación, planificación, implementación y revisión sin delegar la comprensión ni las decisiones de arquitectura.

La persona responsable del repositorio debe poder explicar, modificar y defender cada cambio.

## Flujo obligatorio: Research → Plan → Implement

### Research
La IA debe:
- Leer los archivos implicados y sus dependencias.
- Describir el comportamiento actual.
- Identificar convenciones, contratos y tests existentes.
- Comprobar versiones y compatibilidad.
- Separar hechos verificados de hipótesis.
- No modificar archivos.

### Plan
La IA debe proponer:
- Objetivo y alcance.
- Archivos que cambiará.
- Diseño y responsabilidades.
- Tests, incluidos casos negativos.
- Criterios de aceptación.
- Riesgos y alternativas.

No debe implementar hasta recibir aprobación explícita.

### Implement
La IA debe:
- Cambiar únicamente el alcance aprobado.
- Trabajar en incrementos pequeños.
- Añadir tests relevantes.
- Evitar refactors y dependencias no solicitados.
- Ejecutar los checks disponibles.
- Mostrar diff y explicar decisiones.

## Reglas para la persona responsable

1. No aceptar código que no pueda explicar.
2. Revisar el diff completo antes de integrar.
3. Ejecutar tests localmente, no confiar solo en el resumen del agente.
4. Revisar especialmente tests negativos y de concurrencia.
5. Mantener commits pequeños y descriptivos.
6. Registrar decisiones arquitectónicas en ADRs.
7. Cerrar el chat después de una tarea y comprobar si se puede explicar el diseño sin ayuda.
8. No permitir que la IA cambie versiones mayores, contratos o estructura global sin aprobación.

## Preguntas de revisión para cada cambio

- ¿Qué problema resuelve?
- ¿Por qué esta responsabilidad vive en esta capa?
- ¿Qué alternativa se descartó y por qué?
- ¿Qué invariantes protege?
- ¿Qué ocurre si falla a mitad de operación?
- ¿Qué ocurre si el mensaje se entrega dos veces?
- ¿Qué tests demuestran el comportamiento?
- ¿Qué archivos o módulos existentes pueden verse afectados?

## Límites

- No generar el proyecto completo en una única petición.
- No inventar clases ni métodos de Shared.
- No propagar secretos o datos sensibles en eventos.
- No introducir microservicios, Redis, CQRS complejo o event sourcing sin decisión expresa.
- No ocultar fallos de análisis desactivando reglas o reduciendo cobertura.
- No afirmar que se ejecutó una prueba si no se ejecutó realmente.

## Definición de terminado

Una tarea está terminada cuando:
- Cumple criterios de aceptación.
- Tiene tests adecuados.
- Pasa los checks aplicables o documenta claramente los bloqueos.
- El diff no contiene cambios ajenos.
- La persona responsable entiende el código y puede explicar sus compromisos.
