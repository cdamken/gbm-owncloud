"""
Tests for scripts/verify_dom_ids.py and scripts/verify_wiring.py.

These verifiers are the safety-net that catches the 2026-06-05
class of bugs (settings-btn null reference, stranded JS refs).
If THEY are broken, nothing protects us — so they themselves
need tests.

Uses stdlib unittest only; no pytest, no extra deps.
"""
import subprocess
import sys
import unittest
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
SCRIPTS = REPO_ROOT / 'scripts'


def run_script(name, cwd=None):
    cwd = cwd or REPO_ROOT
    r = subprocess.run(
        [sys.executable, str(SCRIPTS / name)],
        cwd=cwd, capture_output=True, text=True, timeout=30,
    )
    return r.returncode, r.stdout + r.stderr


class TestVerifyScripts(unittest.TestCase):
    """Both verify_* scripts should be green on the repo HEAD at all times."""

    def test_verify_dom_ids_passes_on_current_repo(self):
        """If this fails, someone shipped a stranded DOM-id and didn't notice."""
        rc, out = run_script('verify_dom_ids.py')
        self.assertEqual(rc, 0, f'verify_dom_ids.py FAILED on HEAD:\n{out}')
        self.assertIn('PASS', out)

    def test_verify_wiring_passes_on_current_repo(self):
        """If this fails, someone shipped a JS reference to a non-existent symbol."""
        rc, out = run_script('verify_wiring.py')
        self.assertEqual(rc, 0, f'verify_wiring.py FAILED on HEAD:\n{out}')
        self.assertIn('PASS', out)


class TestVerifyDomIdsRegression(unittest.TestCase):
    """Regression tests for the DOM-id verifier itself.

    These plant a known-bad mini-repo in tmp and run the verifier
    against it, asserting the bad ID is detected. If the verifier
    silently passes on a known bug, it's broken.
    """

    def _build_fake_repo(self, tmpdir, template_html, js_code):
        root = Path(tmpdir)
        (root / 'templates').mkdir()
        (root / 'js').mkdir()
        (root / 'scripts').mkdir()
        (root / 'templates' / 'fake.php').write_text(template_html)
        (root / 'js' / 'fake.js').write_text(js_code)
        (root / 'scripts' / 'verify_dom_ids.py').write_text(
            (SCRIPTS / 'verify_dom_ids.py').read_text()
        )
        return root

    def _run_in(self, repo_root):
        r = subprocess.run(
            [sys.executable, str(repo_root / 'scripts' / 'verify_dom_ids.py')],
            cwd=repo_root, capture_output=True, text=True, timeout=15,
        )
        return r.returncode, r.stdout + r.stderr

    def test_catches_planted_missing_id(self):
        import tempfile
        with tempfile.TemporaryDirectory() as tmp:
            repo = self._build_fake_repo(
                tmp,
                '<button id="real-btn">x</button>',
                "document.getElementById('nonexistent-btn').click();",
            )
            rc, out = self._run_in(repo)
            self.assertEqual(rc, 1, f'Verifier missed the bug:\n{out}')
            self.assertIn('nonexistent-btn', out,
                          f'Bad ID not in output:\n{out}')

    def test_ignores_string_literals_and_comments(self):
        """v0.14.11 regression: references inside // ... or '...' must NOT
        trigger failure."""
        import tempfile
        with tempfile.TemporaryDirectory() as tmp:
            repo = self._build_fake_repo(
                tmp,
                '<button id="real-btn">x</button>',
                """
                // The #fake-comment-id used to exist; we removed it.
                const tip = 'try $(\\'fake-string-id\\')';
                /* Block: $('fake-block-id') */
                document.getElementById('real-btn').click();
                """,
            )
            rc, out = self._run_in(repo)
            self.assertEqual(
                rc, 0,
                f'False positive: comments/strings triggered failure:\n{out}'
            )


if __name__ == '__main__':
    unittest.main()
