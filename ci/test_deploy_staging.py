import os
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


SOURCE = Path(__file__).resolve().parent


class DeploymentGuardsTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        (self.root / "ci").mkdir()
        for name in ("deploy-staging.sh", "deploy-staging-ssh.sh"):
            if (SOURCE / name).exists():
                shutil.copy2(SOURCE / name, self.root / "ci" / name)
        self.git("init", "-q", "-b", "stage")
        self.git("config", "user.email", "deploy-test@example.invalid")
        self.git("config", "user.name", "Deployment test")
        (self.root / "tracked").write_text("original\n")
        self.git("add", ".")
        self.git("commit", "-qm", "fixture")
        self.sha = self.git("rev-parse", "HEAD").strip()
        self.bin = self.root / "bin"
        self.bin.mkdir()
        self.calls = self.root / "docker-calls"
        docker = self.bin / "docker"
        docker.write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$DEPLOY_TEST_CALLS"\nexit 97\n')
        docker.chmod(0o755)

    def git(self, *args):
        return subprocess.check_output(["git", *args], cwd=self.root, text=True)

    def run_script(self, *args, name="deploy-staging.sh", scenario=""):
        return subprocess.run(
            ["bash", str(self.root / "ci" / name), *args],
            cwd=self.root, capture_output=True, text=True,
            env={**os.environ, "PATH": f"{self.bin}:{os.environ['PATH']}",
                 "DEPLOY_TEST_CALLS": str(self.calls),
                 "DEPLOY_BACKUP_ROOT": str(self.root / "backups"),
                 "DEPLOY_TEST_SCENARIO": scenario},
        )

    def assert_rejected(self, result, message):
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(message, result.stderr)
        self.assertFalse(self.calls.exists(), "Rejected deployments must not invoke Docker")

    def test_rejects_invalid_revision(self):
        self.assert_rejected(self.run_script("HEAD; touch unexpected"), "Expected a full commit SHA")
        self.assertFalse((self.root / "unexpected").exists())

    def test_rejects_different_checkout(self):
        self.assert_rejected(self.run_script("0" * 40), "Checkout does not match")

    def test_rejects_other_branch(self):
        self.git("checkout", "-qb", "develop")
        self.assert_rejected(self.run_script(self.sha), "Only the stage branch")

    def test_rejects_dirty_checkout(self):
        (self.root / "tracked").write_text("changed\n")
        self.assert_rejected(self.run_script(self.sha), "Tracked files have local changes")

    def test_clean_stage_reaches_compose_validation(self):
        result = self.run_script(self.sha)
        self.assertEqual(result.returncode, 97, result.stderr)
        self.assertIn("config --quiet", self.calls.read_text())

    def test_ssh_rejects_arbitrary_commands(self):
        self.assert_rejected(self.run_script("bash", name="deploy-staging-ssh.sh"), "Only deploy <commit-sha>")

    def test_ssh_rejects_extra_arguments(self):
        self.assert_rejected(self.run_script(f"deploy {self.sha}; touch unexpected", name="deploy-staging-ssh.sh"), "Only deploy <commit-sha>")
        self.assertFalse((self.root / "unexpected").exists())

    def mock_deployment_commands(self):
        # Docker and HTTP are external side effects; Git and the deploy script are real.
        (self.bin / "docker").write_text('''#!/usr/bin/env python3
import json, os, signal, sys
args = sys.argv[1:]
with open(os.environ["DEPLOY_TEST_CALLS"], "a") as log:
    log.write(json.dumps(args) + "\\n")
if args[0] == "inspect":
    print("sha256:old-php" if args[-1] == "php-container" else "sha256:old-queue")
elif args[0] == "compose" and "ps" in args and "-q" in args:
    if "proxy-php" in args: print("php-container")
    if "proxy-queue" in args: print("queue-container")
elif "sha256sum" in args:
    print("manifest-checksum  /var/www/html/public/build/manifest.json")
elif args[0] == "tag" and args[1].startswith("escriptorium-proxy-release:"):
    if os.environ.get("DEPLOY_TEST_SCENARIO") == "terminate":
        os.kill(os.getppid(), signal.SIGTERM)
''')
        curl = self.bin / "curl"
        curl.write_text('#!/bin/sh\n[ "$DEPLOY_TEST_SCENARIO" != "http-failure" ] || exit 22\n')
        curl.chmod(0o755)

    def deployment_calls(self):
        return [json.loads(line) for line in self.calls.read_text().splitlines()]

    def assert_rolled_back(self, result):
        self.assertNotEqual(result.returncode, 0)
        calls = self.deployment_calls()
        self.assertIn(["tag", "sha256:old-php", "escriptorium-proxy-php:latest"], calls)
        self.assertIn(["tag", "sha256:old-queue", "escriptorium-proxy-queue:latest"], calls)
        self.assertTrue(any("tar -xzf" in " ".join(call) for call in calls))
        self.assertTrue(any(call[-2:] == ["restart", "nginx"] for call in calls))

    def test_successful_deployment_publishes_assets_and_checks_health(self):
        self.mock_deployment_commands()
        result = self.run_script(self.sha)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("Staging deployed successfully", result.stdout)
        calls = self.deployment_calls()
        self.assertTrue(any("php-fpm-healthcheck" in call for call in calls))
        self.assertFalse(any("tar -xzf" in " ".join(call) for call in calls))

    def test_failed_http_check_restores_images_and_assets(self):
        self.mock_deployment_commands()
        self.assert_rolled_back(self.run_script(self.sha, scenario="http-failure"))

    def test_termination_during_release_restores_images_and_assets(self):
        self.mock_deployment_commands()
        self.assert_rolled_back(self.run_script(self.sha, scenario="terminate"))


if __name__ == "__main__":
    unittest.main()
