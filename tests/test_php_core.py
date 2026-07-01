import os
import shutil
import subprocess
import unittest


class PhpCoreTests(unittest.TestCase):
    def test_php_core_suite_passes(self):
        if shutil.which("php") is None:
            self.skipTest("php binary not available")
        root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
        runner = os.path.join(root, "tests", "php", "run_all.php")
        proc = subprocess.run(
            ["php", runner], capture_output=True, text=True
        )
        self.assertEqual(
            proc.returncode, 0,
            "PHP core tests failed:\n%s\n%s" % (proc.stdout, proc.stderr),
        )
