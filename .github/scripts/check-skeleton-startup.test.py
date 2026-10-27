import ctypes
import os
from pathlib import Path
import signal
import subprocess
import tempfile
import time
import unittest


SCRIPT = Path(__file__).resolve().with_name("check-skeleton-startup.sh")


class SkeletonStartupTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        # Adopt the deliberately orphaned fixture worker so the test can reap it.
        cls.libc = ctypes.CDLL(None, use_errno=True)
        if cls.libc.prctl(36, 1, 0, 0, 0) != 0:  # PR_SET_CHILD_SUBREAPER
            raise OSError(ctypes.get_errno(), "Unable to adopt fixture workers")

    @classmethod
    def tearDownClass(cls):
        cls.libc.prctl(36, 0, 0, 0, 0)

    def test_cleanup_stops_workers_after_the_parent_exits(self):
        for homepage_status in (200, 503):
            with self.subTest(homepage_status=homepage_status):
                with tempfile.TemporaryDirectory(prefix="hypervel-startup-test-") as directory:
                    root = Path(directory)
                    (root / "bin").mkdir()
                    (root / "storage/logs").mkdir(parents=True)
                    record = root / "worker.pid"
                    worker = root / "worker.py"
                    worker.write_text(
                        "import os, signal, time\n"
                        "signal.signal(signal.SIGTERM, signal.SIG_IGN)\n"
                        "open(os.environ['WORKER_RECORD'], 'w').write(str(os.getpid()))\n"
                        "while True: time.sleep(0.1)\n"
                    )
                    (root / "bin/php").write_text(
                        "#!/usr/bin/env python3\n"
                        "import os, signal, subprocess, sys, time\n"
                        "signal.signal(signal.SIGTERM, lambda *_: sys.exit(0))\n"
                        "subprocess.Popen([sys.executable, os.environ['WORKER_SCRIPT']])\n"
                        "while True: time.sleep(0.1)\n"
                    )
                    (root / "bin/curl").write_text(
                        "#!/usr/bin/env python3\n"
                        "import os, sys, time\n"
                        "for attempt in range(100):\n"
                        "    if os.path.exists(os.environ['WORKER_RECORD']): break\n"
                        "    time.sleep(0.05)\n"
                        "else: sys.exit(1)\n"
                        "print('200' if sys.argv[-1].endswith('/up') else os.environ['HOMEPAGE_STATUS'], end='')\n"
                    )
                    for name in ("php", "curl"):
                        (root / "bin" / name).chmod(0o755)
                    environment = {
                        **os.environ,
                        "PATH": str(root / "bin") + os.pathsep + os.environ["PATH"],
                        "WORKER_RECORD": str(record),
                        "WORKER_SCRIPT": str(worker),
                        "HOMEPAGE_STATUS": str(homepage_status),
                        "SERVER_HOST": "127.0.0.1",
                        "SERVER_PORT": "18080",
                    }
                    try:
                        result = subprocess.run(
                            ["bash", str(SCRIPT)], cwd=root, env=environment,
                            capture_output=True, text=True, timeout=20,
                        )
                        self.assertEqual(result.returncode, 0 if homepage_status == 200 else 1, result.stdout + result.stderr)
                        if homepage_status == 503:
                            self.assertIn("homepage returned HTTP 503", result.stderr)
                            self.assertIn("Startup diagnostics:", result.stdout)
                        child = int(record.read_text())
                        reaped, status = os.waitpid(child, os.WNOHANG)
                        deadline = time.monotonic() + 2
                        while reaped == 0 and time.monotonic() < deadline:
                            time.sleep(0.01)
                            reaped, status = os.waitpid(child, os.WNOHANG)
                        self.assertEqual(reaped, child, "Cleanup left the worker running after its parent exited")
                        self.assertTrue(os.WIFSIGNALED(status))
                        self.assertEqual(os.WTERMSIG(status), signal.SIGKILL)
                    finally:
                        if record.exists():
                            child = int(record.read_text())
                            try:
                                os.killpg(os.getpgid(child), signal.SIGKILL)
                            except ProcessLookupError:
                                pass
                            # Reap fixture descendants even when the script or an assertion fails.
                            deadline = time.monotonic() + 5
                            while time.monotonic() < deadline:
                                try:
                                    reaped, _ = os.waitpid(-1, os.WNOHANG)
                                except ChildProcessError:
                                    break
                                if reaped == 0:
                                    time.sleep(0.05)


if __name__ == "__main__":
    unittest.main()
