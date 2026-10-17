# Selección de destinatarios — 17/10/2026

El panel ahora permite añadir personas por correo, quitar cualquiera, evitar
duplicados por UID y confirmar la lista completa antes de enviar. Admite hasta
20 personas por operación de interfaz. La selección nunca significa “los últimos
tres usuarios”: el límite existente de tres corresponde a dispositivos de cada
persona. No se alteró ese límite ni se agregó envío indiscriminado a todos.

Se reutiliza POST /api/admin/notifications por cada destinatario. Cada solicitud
conserva su UUID al reintentar: el backend comprueba administrador, usuario,
perfil y dispositivos, reserva el envío y devuelve su resultado sin duplicarlo.
El procesamiento es secuencial, con progreso, resultado individual y errores
parciales. No es una transacción atómica: algunas personas pueden recibir el aviso
y otras fallar. Reintentar conserva los identificadores de todas ellas.
Cambiar de sesión detiene las personas aún no procesadas; no puede retirar una
solicitud ya recibida por el servidor ni un mensaje aceptado por FCM.

Verificación: tres pruebas Node de destinatarios explícitos/deduplicación,
fallo parcial/identidad estable y cambio de sesión. En navegador local se
seleccionaron dos cuentas ficticias, se comprobó la confirmación con ambos
correos y el resultado separado. Ese servidor simula respuestas: no demuestra
recepción real. Sin errores JS observados. Las pruebas PHP existentes pasan.

Graphify 0.9.87: la instalación aislada de uv ya existía y se registró su skill
para Codex. Se agregó su carpeta de comandos al PATH del usuario. El mapa local
de un subconjunto del backend/panel produjo 283 nodos y 731 relaciones, y se
consultaron ManualNotification, NotificationAdmin y FcmSender. Es análisis
estático, no una comprobación del despliegue. Su salida está fuera del repositorio.

Pendiente: publicar con autorización y probar entrega real en Android/iOS.
La URL 127.0.0.1:8766/composer es una vista ficticia; la real está en Vercel.
