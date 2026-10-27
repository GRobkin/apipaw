# T05 — Fotos y recuperación local

Estado local, 20/10/2026. No publicado. No se exportaron datos reales.

## Fotos

`PhotoInput` se aplica a altas y modificaciones CRUD, y a la modificación del perfil. Las fotos nuevas deben ser JPEG con base64 canónico, hasta 409600 bytes de data URI, lado máximo 2048 y máximo 4194304 píxeles. No se descargan URLs ni se acepta SVG/PNG disfrazado de JPEG. Se comprueba estructura, longitudes de segmentos, final del archivo y ausencia de bytes anexos; se eliminan segmentos APP y COM, que pueden incluir EXIF/GPS, comentarios y perfiles. No hay análisis semántico ni moderación de contenido por este mecanismo.

Con GD se decodifican píxeles y se vuelve a codificar JPEG; se rechazan advertencias del decodificador. Sin GD se ejecuta `tool/jpeg-codec.cjs` con Node y `jpeg-js@0.4.4`, fijado en package-lock. También decodifica y recodifica, sin reenviar EXIF/comentarios al encoder. Se limita el decodificador a 4,194304 megapíxeles y 96 MiB aproximados, el proceso Node a 160 MiB de heap y la operación a 10 segundos. No usa archivos temporales ni argumentos que contengan la imagen. Si falta el proceso/dependencia o vence el plazo, responde 503 `PHOTO_PROCESSOR_UNAVAILABLE`: no acepta la foto solo por su encabezado. Un JPEG estructuralmente plausible pero sin tablas de cuantización se rechaza también sin GD.

La documentación del [runtime vercel-php](https://github.com/vercel-community/php#-features) no lista GD. `vercel.json` incluye el helper y el paquete Node en el bundle; antes de publicar se necesita `npm ci --ignore-scripts` y verificar el bundle/runtime real. Instalación local: una dependencia sin scripts; npm informó cero vulnerabilidades en ese momento. La prueba local sin extensiones PHP pasó usando Node. Esto no demuestra todavía su ejecución en Vercel.

Antes de procesar una foto nueva se reserva una cuota versionada de 30 intentos/hora/UID. Incluye intentos inválidos y fallos del procesador para limitar CPU; no se devuelve cuota por fallo. Conservar una foto idéntica, no enviarla o quitarla no consume esta cuota. No se afirma que este control sea un límite global de todas las operaciones de la app.

Un valor histórico idéntico enviado de nuevo en una edición se conserva; no se migra ni descarga. Permite editar registros antiguos que contienen una URL sin introducir otra. Fotos no reenviadas no se modifican. `null` permite quitar la foto. La imagen de perfil obtenida de Firebase Auth durante la creación automática sigue siendo un dato del proveedor; no pasa por la ruta de subida del usuario. El saneamiento de imágenes históricas requiere una migración explícita, no una lectura de la app.

Flutter convierte JPEG/PNG seleccionados a JPEG real mediante la dependencia `image` que ya existía. Comprueba tamaño antes de leer y antes de decodificar; normaliza orientación, reduce a 256 píxeles y copia únicamente píxeles a una imagen nueva. Así no hereda EXIF/GPS. Limita entrada a 10 MiB y 4096 por lado/16 megapíxeles; rechaza animación. El PNG compartido del paseo y la captura del mapa conservan sus flujos existentes. No se añadió una dependencia a Flutter ni se rediseñó su interfaz.

Pruebas: fixture JPEG sintético, dimensiones falsas, truncado, bytes anexos, base64 inválido, EXIF simulado, URL/SVG, edición de valores históricos y rechazo previo a escritura CRUD. Se ejecuta PHP con GD y también con `php -n` sin extensiones. Flutter prueba PNG→JPEG, reducción, eliminación de EXIF y entradas inválidas. No hay prueba física de cámara/galería iOS/Android en esta etapa.

## Recuperación implementada

`tool/recovery.py` proporciona `keygen`, `export`, `verify` y `restore`. La exportación usa PHP y las credenciales administrativas ya configuradas, por un pipe privado; no hay endpoint HTTP de backup. No se ejecutó exportación de producción ni se habilitaron servicios pagos/tareas recurrentes. Solo se crearon archivos sintéticos temporales durante las pruebas y se eliminaron al terminar.

`BackupExport` enumera colecciones raíz y subcolecciones, incluyendo descendientes de documentos padre ausentes mediante `showMissing`. Pagina colecciones y documentos con tokens, detecta ciclos y conserva los tipos REST originales (enteros de 64 bits, bytes, referencias, mapas vacíos). No utiliza los modelos móviles ni su límite de páginas. Límite defensivo: 100000 lecturas paginadas y profundidad 100; excederlos invalida el intento. El wrapper limita el contenido plano a 64 MiB en memoria; para una base mayor se necesita otra solución revisada, no eliminar el límite por intuición.

Controles del artefacto:

- Cifrado autenticado con la implementación [Fernet de cryptography](https://cryptography.io/en/latest/fernet/), sin construir primitivas criptográficas propias. Dependencia local fijada en `tool/requirements-recovery.txt`. La clave aleatoria vive en un archivo fuera de ambos repositorios y en carpeta distinta de la copia; no se envía su contenido como argumento ni se imprime. Fernet requiere el mensaje completo en memoria y expone la fecha de creación del token; de ahí el límite de tamaño. En Windows hay que custodiar las carpetas con ACL restringidas: el modo 0600 de Python no reemplaza esa configuración.
- Flujo sin archivo temporal plano, salida fuera de ambos repositorios y de carpetas públicas; archivo parcial claramente distinguible y no promovido si falla lectura/cifrado.
- Registros de inicio/fin versionados, proyecto de origen, fechas, colecciones y conteos. Fernet verifica autenticidad antes de devolver el contenido descifrado; `verify` además informa SHA-256 del contenido autenticado. Una lectura por páginas no es una instantánea transaccional global: realizar bajo un procedimiento que contemple escrituras concurrentes.
- No incluir credenciales de cuenta de servicio. El archivo contiene datos privados (incluidos GPS/tokens) y requiere custodia restringida. Firebase Auth, índices, reglas y configuración requieren inventario/copia aparte: Firestore no los incluye.
- Restauración únicamente a HTTP loopback numérico (`127.0.0.1`/`::1`) con puerto explícito y proyecto `demo-*` distinto al origen. Rechaza producción, proxies y redirecciones. Reescribe referencias internas al proyecto demo; referencias externas se conservan sin seguirlas. No restaura Firebase Auth ni ejecuta el backend/programador. No crea documentos para padres ausentes ni puede materializar colecciones vacías: se conservan en el manifiesto.
- Validar por completo el artefacto antes de escribir. Si una restauración falla a mitad, registrar estado y permitir repetir sin alterar un documento ajeno o diferente. Prueba con más de una página, tipos REST, referencias, bytes, colecciones vacías y subcolecciones.

Las escrituras usan `currentDocument.exists=false`. Tras fallo puede repetirse: solo acepta como ya restaurado un documento con los mismos campos; ante diferencias se detiene sin sobrescribir. El archivo completo, tipos, rutas y conteos se validan antes de la primera escritura. Un fallo a mitad deja un sandbox parcial identificable; no se afirma atomicidad global de restauración.

Pruebas aprobadas: exportación paginada con transporte doble y tipos REST; cuatro pruebas Python de cifrado/alteración/clave incorrecta, conteos y tipos inválidos, destinos prohibidos y restauración/reanudación de 25 documentos contra servidor HTTP sintético. Una prueba inyecta fallo después de la primera escritura y comprueba repetición sin pérdida. El wrapper informa conteos/duración por ejecución; no se deducen RPO/RTO de tiempos sintéticos.

Falta ensayo contra el emulador oficial de Firestore y verificación de IAM/lectura real. No se encontró Java ni una imagen de emulador disponible en las ubicaciones comprobadas; no se instaló un runtime global. Sigue pendiente Astra por límite de uso. Definir responsable, frecuencia, retención y destino antes de automatizar o exportar datos reales.

## Uso manual posterior a revisión

Desde apipaw, con carpetas privadas externas a ambos repositorios ya creadas (las siguientes son ejemplos, no se ejecutaron):

```powershell
python tool/recovery.py keygen --key-file C:\PawLifeKeys\backup.key
python tool/recovery.py export --key-file C:\PawLifeKeys\backup.key --archive C:\PawLifeCopies\manual.fernet
python tool/recovery.py verify --key-file C:\PawLifeKeys\backup.key --archive C:\PawLifeCopies\manual.fernet
python tool/recovery.py restore --key-file C:\PawLifeKeys\backup.key --archive C:\PawLifeCopies\manual.fernet --emulator http://127.0.0.1:8080 --project demo-pawlife-restore
```

No ejecutar `export-firestore.php` directamente ni redirigir su salida a un archivo. Un código distinto de cero significa que no se declara completada la operación. Guardar el resultado de conteos/tiempos sin copiar contenidos privados. El cifrado no sustituye custodia, retención ni una prueba de restauración real.
