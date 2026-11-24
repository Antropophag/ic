"""Check exported settings at the real Make lifecycle entry points."""

import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]


class LocalMakeSettingsTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        shutil.copyfile(ROOT / "Makefile", self.directory / "Makefile")
        (self.directory / "scripts").mkdir()
        (self.directory / "scripts/environment.sh").write_text(
            'printf "%s|%s|%s\\n" "${BUILD_TLS_COMPAT-unset}" '
            '"${GODEBUG-unset}" "${FRONTEND_PORT-unset}"\n'
        )

    def run_make(self, target, *options):
        env = os.environ.copy()
        for key in ("BUILD_TLS_COMPAT", "GODEBUG", "FRONTEND_PORT",
                    "MAKEFLAGS", "MFLAGS", "MAKEOVERRIDES", "MAKEFILES"):
            env.pop(key, None)
        return subprocess.run(
            ["make", "--no-print-directory", "COMPOSE=true", "CONTAINER_ENGINE=true",
             *([target] if target else []), *options], cwd=self.directory, env=env,
            check=True, capture_output=True, text=True,
        ).stdout.strip()

    def install_settings(self):
        shutil.copyfile(ROOT / "Makefile.local.example", self.directory / "Makefile.local")

    def test_missing_local_file_preserves_default_environment(self):
        self.assertEqual(self.run_make("dev-up"), "unset|unset|unset")

    def test_dev_commands_export_saved_settings(self):
        self.install_settings()
        for target in ("dev-up", "dev-restart"):
            with self.subTest(target=target):
                self.assertEqual(self.run_make(target), "1|netedns0=0,tlsmlkem=0|8080")

    def test_dev_settings_do_not_affect_production(self):
        self.install_settings()
        self.assertEqual(self.run_make("prod-up"), "unset|unset|unset")

    def test_command_line_can_override_saved_port(self):
        self.install_settings()
        self.assertEqual(
            self.run_make("dev-up", "FRONTEND_PORT=9001"),
            "1|netedns0=0,tlsmlkem=0|9001",
        )

    def test_local_targets_do_not_replace_default_help(self):
        self.install_settings()
        self.assertIn("make help", self.run_make(None))


if __name__ == "__main__":
    unittest.main()
