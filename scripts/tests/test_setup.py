import base64
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path


REPOSITORY = Path(__file__).resolve().parents[2]
EXISTING_KEY = "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="


class SetupEnvironmentTests(unittest.TestCase):
    def setUp(self):
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary_directory.cleanup)
        self.root = Path(self.temporary_directory.name)
        for directory in ("scripts", "proxy", "escriptorium"):
            (self.root / directory).mkdir()
        for name in (
            "scripts/setup.sh",
            ".env.development.example",
            "docker-compose.development.yml",
            "escriptorium/variables.env_example",
        ):
            shutil.copy2(REPOSITORY / name, self.root / name)
        # This fixture already has its eScriptorium checkout; no network is needed.
        (self.root / "escriptorium/Dockerfile").touch()
        self.root_env = self.root / ".env.development"
        self.proxy_env = self.root / "proxy/.env"

    def run_setup(self):
        result = subprocess.run(
            ["bash", str(self.root / "scripts/setup.sh")],
            cwd=self.root,
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def app_key(self, path):
        for line in path.read_text().splitlines():
            if line.startswith("APP_KEY="):
                return line.partition("=")[2].strip("\"'")
        return ""

    def test_fresh_setup_creates_one_valid_persistent_key(self):
        self.run_setup()
        key = self.app_key(self.root_env)
        self.assertTrue(key.startswith("base64:"))
        self.assertEqual(len(base64.b64decode(key[7:], validate=True)), 32)
        self.assertEqual(self.app_key(self.proxy_env), key)
        before = [path.read_bytes() for path in (self.root_env, self.proxy_env)]
        self.run_setup()
        self.assertEqual(
            [path.read_bytes() for path in (self.root_env, self.proxy_env)], before
        )

    def test_setup_reuses_proxy_key_when_docker_key_is_empty_or_missing(self):
        proxy_contents = f'APP_KEY="{EXISTING_KEY}"\nAPP_NAME=ExistingProxy\n'
        self.proxy_env.write_text(proxy_contents)
        for assignment in ("APP_KEY=\n", 'APP_KEY=""\n', "APP_KEY=''\n", ""):
            with self.subTest(assignment=assignment):
                self.root_env.write_text("APP_NAME=ExistingDocker\n" + assignment)
                self.run_setup()
                self.assertEqual(self.app_key(self.root_env), EXISTING_KEY)
                self.assertIn("APP_NAME=ExistingDocker\n", self.root_env.read_text())
                self.assertEqual(self.proxy_env.read_text(), proxy_contents)

    def test_setup_reuses_proxy_key_when_creating_docker_env(self):
        self.proxy_env.write_text(f"APP_KEY={EXISTING_KEY}\n")
        self.run_setup()
        self.assertEqual(self.app_key(self.root_env), EXISTING_KEY)
        self.assertEqual(self.app_key(self.proxy_env), EXISTING_KEY)

    def test_setup_fills_empty_proxy_key_without_replacing_its_settings(self):
        root_contents = f"APP_KEY={EXISTING_KEY}\nAPP_NAME=ExistingDocker\n"
        self.root_env.write_text(root_contents)
        self.proxy_env.write_text("APP_KEY=\nAPP_NAME=ExistingProxy\n")
        self.run_setup()
        self.assertEqual(self.app_key(self.proxy_env), EXISTING_KEY)
        self.assertIn("APP_NAME=ExistingProxy\n", self.proxy_env.read_text())
        self.assertEqual(self.root_env.read_text(), root_contents)

    def test_setup_preserves_explicit_keys_and_settings(self):
        root_contents = f"APP_KEY={EXISTING_KEY}\nAPP_NAME=ExistingDocker\n"
        proxy_contents = "APP_KEY=another-existing-32-byte-app-key!\nAPP_NAME=ExistingProxy\n"
        self.root_env.write_text(root_contents)
        self.proxy_env.write_text(proxy_contents)
        self.run_setup()
        self.assertEqual(self.root_env.read_text(), root_contents)
        self.assertEqual(self.proxy_env.read_text(), proxy_contents)


if __name__ == "__main__":
    unittest.main()
