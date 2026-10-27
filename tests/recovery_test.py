"""Synthetic HTTP sandbox; no Firebase credentials or production traffic."""
import importlib.util
import json
from pathlib import Path
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from cryptography.fernet import Fernet, InvalidToken

spec = importlib.util.spec_from_file_location('recovery', Path(__file__).parents[1] / 'tool/recovery.py')
recovery = importlib.util.module_from_spec(spec)
spec.loader.exec_module(recovery)


def fixture(count=25):
    records = [{'type': 'begin', 'format': 1, 'project': 'source-project', 'startedAt': '2026-10-20T00:00:00Z'},
        {'type': 'collection', 'path': 'pets'}, {'type': 'collection', 'path': 'empty'}]
    fields = {'number': {'integerValue': '9223372036854775807'}, 'binary': {'bytesValue': 'YWJj'},
        'geo': {'geoPointValue': {'latitude': -34.9, 'longitude': -56.2}},
        'ref': {'referenceValue': 'projects/source-project/databases/(default)/documents/pets/0'},
        'map': {'mapValue': {'fields': {}}}, 'array': {'arrayValue': {'values': [{'nullValue': None}]}}}
    records += [{'type': 'document', 'path': f'pets/{i}', 'fields': fields} for i in range(count)]
    records += [{'type': 'end', 'documents': count, 'collections': 2, 'finishedAt': '2026-10-20T00:00:01Z'}]
    return ('\n'.join(json.dumps(r) for r in records) + '\n').encode()


class Sandbox(BaseHTTPRequestHandler):
    records = {}
    fail_at = None
    writes = 0

    def log_message(self, *args):
        pass

    def respond(self, code, data):
        body = json.dumps(data).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_PATCH(self):
        path = self.path.split('?', 1)[0]
        body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        if type(self).fail_at is not None and type(self).writes == type(self).fail_at:
            type(self).fail_at = None
            return self.respond(503, {})
        if path in type(self).records:
            return self.respond(412, {})
        self.assert_precondition = 'currentDocument.exists=false' in self.path
        if not self.assert_precondition:
            return self.respond(400, {})
        type(self).records[path] = body
        type(self).writes += 1
        self.respond(200, body)

    def do_GET(self):
        self.respond(200, type(self).records[self.path])


class RecoveryTests(unittest.TestCase):
    def test_encrypted_roundtrip_and_corruption(self):
        raw, cipher = fixture(), Fernet(Fernet.generate_key())
        encrypted = recovery.encrypt_archive(raw, cipher)
        self.assertNotIn(b'9223372036854775807', encrypted)
        with tempfile.TemporaryDirectory() as directory:
            archive = Path(directory) / 'copy.fernet'
            recovery.atomic_private_write(archive, encrypted)
            restored, (_, docs, end) = recovery.decrypt_archive(archive, cipher)
            self.assertEqual(restored, raw)
            self.assertEqual(len(docs), 25)
            self.assertEqual(end['collections'], 2)
            with self.assertRaises(ValueError):
                recovery.atomic_private_write(archive, b'replacement')
            altered = bytearray(encrypted)
            altered[100] ^= 1
            archive.write_bytes(altered)
            with self.assertRaises(InvalidToken):
                recovery.decrypt_archive(archive, cipher)
            archive.write_bytes(encrypted)
            with self.assertRaises(InvalidToken):
                recovery.decrypt_archive(archive, Fernet(Fernet.generate_key()))

    def test_invalid_or_partial_archive_rejected_before_restore(self):
        for raw in [fixture().rsplit(b'\n', 2)[0], fixture().replace(b'"documents": 25', b'"documents": 99'),
            fixture().replace(b'"integerValue": "9223372036854775807"', b'"unknownType": "x"'),
            fixture().replace(b'pets/0', b'../0')]:
            with self.assertRaises((ValueError, json.JSONDecodeError)):
                recovery.validate(raw)

    def test_no_production_destination_or_repo_files(self):
        for url, project in [('https://firestore.googleapis.com', 'demo-safe'),
            ('http://example.test:8080', 'demo-safe'), ('http://127.0.0.1:8080', 'pawlife-84d14'),
            ('http://127.0.0.1:8080/path', 'demo-safe')]:
            with self.assertRaises(ValueError):
                recovery.sandbox_url(url, project, 'source-project')
        with self.assertRaises(ValueError):
            recovery.outside_repos(recovery.ROOT / 'key')

    def test_sandbox_restore_resume_and_conflict(self):
        Sandbox.records, Sandbox.writes, Sandbox.fail_at = {}, 0, 1
        server = ThreadingHTTPServer(('127.0.0.1', 0), Sandbox)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            url = f'http://127.0.0.1:{server.server_port}'
            data = recovery.validate(fixture())
            with self.assertRaises(ValueError):
                recovery.restore(data, url, 'demo-pawlife-restore')
            self.assertEqual(len(Sandbox.records), 1)
            result = recovery.restore(data, url, 'demo-pawlife-restore')
            self.assertEqual(result, {'created': 24, 'alreadyEqual': 1})
            self.assertEqual(len(Sandbox.records), 25)
            fields = next(iter(Sandbox.records.values()))['fields']
            self.assertEqual(fields['number']['integerValue'], '9223372036854775807')
            self.assertIn('projects/demo-pawlife-restore/', fields['ref']['referenceValue'])
            self.assertEqual(recovery.restore(data, url, 'demo-pawlife-restore'), {'created': 0, 'alreadyEqual': 25})
            next(iter(Sandbox.records.values()))['fields']['number'] = {'integerValue': '0'}
            with self.assertRaises(ValueError):
                recovery.restore(data, url, 'demo-pawlife-restore')
            self.assertEqual(fields['number']['integerValue'], '0')
        finally:
            server.shutdown()
            thread.join()
            server.server_close()


if __name__ == '__main__':
    unittest.main()
