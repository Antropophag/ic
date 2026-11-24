"""Exercise build-only TLS selection and certificate validation over localhost."""

import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest


ROOT = Path(__file__).resolve().parents[2]
WRAPPER = ROOT / "scripts/build-with-tls-compat.sh"


class BuildTlsCompatibilityTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.directory = Path(cls.temp.name)
        cls.cert = cls.directory / "cert.pem"
        cls.key = cls.directory / "key.pem"
        subprocess.run(
            ["openssl", "req", "-x509", "-newkey", "ec", "-pkeyopt",
             "ec_paramgen_curve:P-256", "-nodes", "-keyout", str(cls.key),
             "-out", str(cls.cert), "-days", "1", "-subj", "/CN=localhost",
             "-addext", "subjectAltName=DNS:localhost"],
            check=True, capture_output=True,
        )

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def environment(self, mode):
        env = os.environ.copy()
        for name in ("OPENSSL_CONF", "NODE_OPTIONS", "NODE_EXTRA_CA_CERTS",
                     "NODE_TLS_REJECT_UNAUTHORIZED"):
            env.pop(name, None)
        env["BUILD_TLS_COMPAT"] = mode
        return env

    def test_tls_groups_and_certificate_validation(self):
        for group in ("X25519", "P-521"):
            with socket.socket() as sock:
                sock.bind(("127.0.0.1", 0))
                port = sock.getsockname()[1]
            server = subprocess.Popen(
                ["openssl", "s_server", "-accept", f"127.0.0.1:{port}",
                 "-cert", str(self.cert), "-key", str(self.key),
                 "-groups", group, "-tls1_3", "-www"],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
            try:
                deadline = time.monotonic() + 5
                while True:
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=.1):
                            break
                    except OSError:
                        if server.poll() is not None or time.monotonic() >= deadline:
                            self.fail("Local TLS server did not start")
                        time.sleep(.02)
                for mode, trusted in (("0", True), ("1", True), ("1", False)):
                    with self.subTest(group=group, mode=mode, trusted=trusted):
                        env = self.environment(mode)
                        if trusted:
                            env["NODE_EXTRA_CA_CERTS"] = str(self.cert)
                        script = f"""
const request = require('node:https').get({{
  host: '127.0.0.1', port: {port}, servername: 'localhost'
}}, response => {{ response.resume(); process.exitCode = response.statusCode === 200 ? 0 : 1; }});
request.setTimeout(2000, () => request.destroy(new Error('timeout')));
request.on('error', error => {{ console.error(error.code || error.message); process.exitCode = 1; }});
"""
                        result = subprocess.run(
                            ["sh", str(WRAPPER), "node", "-e", script], env=env,
                            capture_output=True, text=True, timeout=5,
                        )
                        expected_success = trusted and (mode == "0" or group == "X25519")
                        self.assertEqual(result.returncode == 0, expected_success, result.stderr)
            finally:
                server.terminate()
                server.wait(timeout=5)

    def test_default_preserves_environment_and_exit_status(self):
        env = self.environment("0")
        env["OPENSSL_CONF"] = "/existing/config"
        result = subprocess.run(
            ["sh", str(WRAPPER), "sh", "-c", 'printf "%s" "$OPENSSL_CONF"; exit 17'],
            env=env, capture_output=True, text=True,
        )
        self.assertEqual(result.returncode, 17)
        self.assertEqual(result.stdout, "/existing/config")

    def test_temporary_config_is_removed_on_success_and_failure(self):
        for status in (0, 17):
            with self.subTest(status=status):
                result = subprocess.run(
                    ["sh", str(WRAPPER), "sh", "-c",
                     f'test -s "$OPENSSL_CONF" || exit 99; printf "%s" "$OPENSSL_CONF"; exit {status}'],
                    env=self.environment("1"), capture_output=True, text=True,
                )
                self.assertEqual(result.returncode, status, result.stderr)
                self.assertTrue(result.stdout)
                self.assertFalse(Path(result.stdout).exists())

    def test_invalid_mode_does_not_run_command(self):
        result = subprocess.run(
            ["sh", str(WRAPPER), "sh", "-c", "echo executed"],
            env=self.environment("invalid"), capture_output=True, text=True,
        )
        self.assertEqual(result.returncode, 2)
        self.assertEqual(result.stdout, "")


if __name__ == "__main__":
    unittest.main()
