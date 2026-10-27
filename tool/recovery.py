"""Offline Firestore backup. No public route, plaintext files or production restore."""
import argparse
import base64
from datetime import datetime
import hashlib
import json
import math
import os
from pathlib import Path
import re
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

from cryptography.fernet import Fernet

ROOT = Path(__file__).resolve().parents[1]
MAX_BYTES = 64 * 1024 * 1024


def validate_fields(fields, depth=0):
    if not isinstance(fields, dict) or depth > 32:
        raise ValueError('Campos REST inválidos.')
    for name, value in fields.items():
        if not isinstance(name, str) or not name or len(name.encode()) > 1500:
            raise ValueError('Nombre de campo inválido.')
        validate_value(value, depth + 1)


def validate_value(value, depth):
    if not isinstance(value, dict) or len(value) != 1 or depth > 32:
        raise ValueError('Valor REST inválido.')
    kind, item = next(iter(value.items()))
    valid = False
    if kind == 'nullValue':
        valid = item is None or item == 'NULL_VALUE'
    elif kind == 'stringValue':
        valid = isinstance(item, str)
    elif kind == 'booleanValue':
        valid = isinstance(item, bool)
    elif kind == 'integerValue':
        valid = isinstance(item, str) and re.fullmatch(r'-?[0-9]{1,20}', item) and -(2**63) <= int(item) < 2**63
    elif kind == 'doubleValue':
        valid = (type(item) in (int, float) and math.isfinite(item)) or item in ('NaN', 'Infinity', '-Infinity')
    elif kind == 'timestampValue':
        valid = isinstance(item, str) and re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,9})?(?:Z|[+-]\d\d:\d\d)', item)
        if valid:
            try:
                datetime.fromisoformat(item.replace('Z', '+00:00'))
            except ValueError:
                valid = False
    elif kind == 'referenceValue':
        valid = isinstance(item, str) and re.fullmatch(r'projects/[^/]+/databases/[^/]+/documents/.+', item)
        if valid:
            check_path(item.split('/documents/', 1)[1], 0)
    elif kind == 'bytesValue':
        if isinstance(item, str):
            try:
                valid = base64.b64encode(base64.b64decode(item, validate=True)).decode() == item
            except ValueError:
                pass
    elif kind == 'geoPointValue':
        valid = (isinstance(item, dict) and set(item) == {'latitude', 'longitude'}
            and type(item['latitude']) in (int, float) and type(item['longitude']) in (int, float)
            and -90 <= item['latitude'] <= 90 and -180 <= item['longitude'] <= 180)
    elif kind == 'mapValue':
        valid = isinstance(item, dict) and set(item) <= {'fields'}
        if valid:
            validate_fields(item.get('fields', {}), depth + 1)
    elif kind == 'arrayValue':
        valid = isinstance(item, dict) and set(item) <= {'values'} and isinstance(item.get('values', []), list)
        if valid:
            for child in item.get('values', []):
                validate_value(child, depth + 1)
    if not valid:
        raise ValueError('Tipo REST inválido.')


def outside_repos(path):
    resolved = Path(path).resolve()
    for repo in (ROOT, ROOT.parent / 'PawLife'):
        if resolved == repo or repo in resolved.parents:
            raise ValueError('El archivo debe estar fuera de los repositorios.')
    return resolved


def key_at(path):
    key_file = outside_repos(path)
    if key_file.stat().st_size != 44:
        raise ValueError('Clave Fernet inválida.')
    return Fernet(key_file.read_bytes())


def check_path(value, parity):
    if not isinstance(value, str) or len(value.encode()) > 6144:
        raise ValueError('Ruta inválida.')
    parts = value.split('/')
    if len(parts) > 200 or len(parts) % 2 != parity:
        raise ValueError('Ruta inválida.')
    for part in parts:
        if not part or part in ('.', '..') or len(part.encode()) > 1500 or any(ord(c) < 32 or ord(c) == 127 for c in part):
            raise ValueError('Identificador inválido.')


def validate(raw):
    if len(raw) > MAX_BYTES:
        raise ValueError('La copia supera el límite local de 64 MiB.')
    records = [json.loads(line) for line in raw.splitlines()]
    if len(records) < 2 or any(not isinstance(r, dict) for r in records):
        raise ValueError('Copia inválida.')
    begin, end = records[0], records[-1]
    if begin.get('type') != 'begin' or begin.get('format') != 1 or end.get('type') != 'end':
        raise ValueError('Copia incompleta o versión desconocida.')
    if not re.fullmatch(r'[a-z][a-z0-9-]{3,62}', begin.get('project', '')):
        raise ValueError('Proyecto inválido.')
    paths, collections, docs = set(), set(), []
    for record in records[1:-1]:
        kind, path = record.get('type'), record.get('path')
        if kind == 'collection':
            check_path(path, 1)
            if path in collections:
                raise ValueError('Colección duplicada.')
            collections.add(path)
        elif kind == 'document':
            check_path(path, 0)
            if path in paths or not isinstance(record.get('fields'), dict):
                raise ValueError('Documento inválido o duplicado.')
            # Round-trip raw REST types; do not coerce integers, references or bytes.
            if len(json.dumps(record['fields']).encode()) > 1024 * 1024:
                raise ValueError('Documento excesivo.')
            paths.add(path)
            validate_fields(record['fields'])
            docs.append(record)
        else:
            raise ValueError('Registro desconocido.')
    if end.get('documents') != len(docs) or end.get('collections') != len(collections):
        raise ValueError('Conteos de copia incompletos.')
    if any(doc['path'].rsplit('/', 1)[0] not in collections for doc in docs):
        raise ValueError('Falta colección en el manifiesto.')
    return begin, docs, end


def encrypt_archive(raw, cipher):
    validate(raw)
    return cipher.encrypt(raw)


def decrypt_archive(path, cipher):
    path = outside_repos(path)
    if path.stat().st_size > MAX_BYTES * 2:
        raise ValueError('Archivo cifrado excesivo.')
    raw = cipher.decrypt(path.read_bytes())
    return raw, validate(raw)


def atomic_private_write(path, data):
    path = outside_repos(path)
    partial = path.with_name(path.name + '.partial')
    # Exclusive creation: never overwrite another backup or key.
    if path.exists():
        raise ValueError('El destino ya existe.')
    descriptor = os.open(partial, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        # Hard link is atomic and fails if destination appeared meanwhile.
        os.link(partial, path)
    finally:
        partial.unlink(missing_ok=True)


def export_raw():
    environment = dict(os.environ, PAWLIFE_BACKUP_PIPE='1')
    process = subprocess.Popen(['php', str(ROOT / 'tool/export-firestore.php')],
        cwd=ROOT, env=environment, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    watchdog = threading.Timer(600, process.kill)
    watchdog.daemon = True
    watchdog.start()
    data = bytearray()
    try:
        while True:
            chunk = process.stdout.read(65536)
            if not chunk:
                break
            data.extend(chunk)
            if len(data) > MAX_BYTES:
                raise ValueError('Exportación excesiva; no se guardó una copia parcial.')
        if process.wait(timeout=30) != 0:
            raise ValueError('La exportación no terminó correctamente.')
    finally:
        watchdog.cancel()
        if process.poll() is None:
            process.kill()
            process.wait()
        process.stdout.close()
    return bytes(data)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ValueError('Redirección rechazada.')


def sandbox_url(url, project, source):
    parsed = urllib.parse.urlsplit(url)
    if (parsed.scheme != 'http' or parsed.hostname not in ('127.0.0.1', '::1')
        or parsed.username or parsed.password or parsed.path not in ('', '/') or parsed.query or parsed.fragment
        or not parsed.port or not re.fullmatch(r'demo-[a-z0-9-]{3,40}', project) or project == source):
        raise ValueError('Restauración permitida solo en emulador loopback y proyecto demo distinto del origen.')
    return url.rstrip('/') + '/v1/projects/' + project + '/databases/(default)/documents/'


def remap_references(value, source, target):
    if isinstance(value, list):
        return [remap_references(v, source, target) for v in value]
    if not isinstance(value, dict):
        return value
    result = {k: remap_references(v, source, target) for k, v in value.items()}
    prefix = f'projects/{source}/databases/(default)/documents/'
    reference = result.get('referenceValue')
    if isinstance(reference, str) and reference.startswith(prefix):
        result['referenceValue'] = f'projects/{target}/databases/(default)/documents/' + reference[len(prefix):]
    return result


def restore(validated, emulator, project):
    begin, docs, _ = validated
    base = sandbox_url(emulator, project, begin['project'])
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    restored, same = 0, 0
    for doc in docs:
        fields = remap_references(doc['fields'], begin['project'], project)
        url = base + '/'.join(urllib.parse.quote(p, safe='') for p in doc['path'].split('/'))
        request = urllib.request.Request(url + '?currentDocument.exists=false',
            data=json.dumps({'fields': fields}).encode(), method='PATCH', headers={'Content-Type': 'application/json'})
        try:
            with opener.open(request, timeout=15) as response:
                response.read(1024 * 1024 + 1)
            restored += 1
        except urllib.error.HTTPError as error:
            # Resume only by comparing the existing whole document, never overwrite.
            if error.code not in (400, 409, 412):
                raise ValueError('Falló la escritura en sandbox.') from None
            error.close()
            try:
                with opener.open(url, timeout=15) as response:
                    existing = json.loads(response.read(1024 * 1024 + 1))
            except Exception:
                raise ValueError('No se pudo comprobar el documento existente.') from None
            if existing.get('fields', {}) != fields:
                raise ValueError('Sandbox tiene un documento distinto; no se sobrescribió.')
            same += 1
    return {'created': restored, 'alreadyEqual': same}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['keygen', 'export', 'verify', 'restore'])
    parser.add_argument('--key-file', required=True)
    parser.add_argument('--archive')
    parser.add_argument('--emulator')
    parser.add_argument('--project')
    args = parser.parse_args()
    started = time.monotonic()
    if args.action == 'keygen':
        atomic_private_write(args.key_file, Fernet.generate_key())
        print('Clave creada. Guardala separada de la copia y fuera de Git.')
        return
    cipher = key_at(args.key_file)
    if not args.archive:
        parser.error('--archive requerido')
    if outside_repos(args.archive).parent == outside_repos(args.key_file).parent:
        raise ValueError('La copia y la clave deben guardarse en carpetas separadas.')
    if args.action == 'export':
        destination = outside_repos(args.archive)
        if not destination.parent.is_dir() or destination.exists() or destination.with_name(destination.name + '.partial').exists():
            raise ValueError('Destino inexistente u ocupado; no se inició exportación.')
        raw = export_raw()
        begin, docs, end = validate(raw)
        atomic_private_write(args.archive, encrypt_archive(raw, cipher))
        result = {'documents': len(docs), 'collections': end['collections']}
    else:
        raw, validated = decrypt_archive(args.archive, cipher)
        result = {'documents': len(validated[1]), 'sha256': hashlib.sha256(raw).hexdigest()}
        if args.action == 'restore':
            if not args.emulator or not args.project:
                parser.error('--emulator y --project requeridos')
            result.update(restore(validated, args.emulator, args.project))
    result['elapsedSeconds'] = round(time.monotonic() - started, 3)
    print(json.dumps(result))


if __name__ == '__main__':
    try:
        main()
    except Exception:
        # No paths, tokens, payloads, account names or exception text in logs.
        print('RECOVERY_FAILED: revisar archivo, clave, configuración y destino. No se declara completada la operación.', file=sys.stderr)
        sys.exit(1)
