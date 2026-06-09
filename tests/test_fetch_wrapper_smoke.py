"""
Smoke tests for python/fetch_wrapper.py — the subprocess that
ApiController spawns to fetch GBM data.

These DO NOT hit the real GBM API. They verify that the wrapper:
  • parses CLI flags correctly
  • returns the right exit code when env vars are missing
  • handles the --full flag without crashing
  • imports cleanly (no syntax errors, no top-level side effects
    that would break import-time)

Run with:  python3 -m unittest discover -s tests/

Uses stdlib unittest only — no pytest, no extra deps. CI doesn't
need to `pip install` anything to run these.
"""
import os
import subprocess
import sys
import unittest
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
WRAPPER = REPO_ROOT / 'python' / 'fetch_wrapper.py'

# Exit codes from GbmService.php — keep in sync.
EXIT_OK = 0
EXIT_MFA_REQUIRED = 10
EXIT_MFA_INVALID = 11
EXIT_AUTH_FAILED = 12
EXIT_API_ERROR = 20
EXIT_TIMEOUT = 21
EXIT_CONFIG_ERROR = 30


def run_wrapper(args=None, env_extra=None, timeout=10):
    """Spawn the wrapper with controlled env, return (rc, stdout, stderr)."""
    env = {
        'PATH': os.environ.get('PATH', '/usr/bin:/bin'),
        'HOME': '/tmp',
        'LANG': 'C.UTF-8',
    }
    if env_extra:
        env.update(env_extra)
    try:
        r = subprocess.run(
            [sys.executable, str(WRAPPER), *(args or [])],
            env=env, capture_output=True, text=True, timeout=timeout,
        )
        return r.returncode, r.stdout, r.stderr
    except subprocess.TimeoutExpired:
        return -1, '', f'TIMEOUT after {timeout}s'


class TestFetchWrapperSmoke(unittest.TestCase):
    """Sanity checks that don't talk to the real GBM API."""

    def test_wrapper_file_exists(self):
        self.assertTrue(WRAPPER.exists(), f'fetch_wrapper.py not found at {WRAPPER}')

    def test_wrapper_runs_without_python_crash(self):
        """The wrapper must execute without a Python crash (syntax error,
        bad imports, etc). It's OK to exit with CONFIG_ERROR (30) if
        gbm-mx-api isn't installed in the test environment — what we
        care about is "no unhandled exception"."""
        rc, _out, err = run_wrapper(['--help'])
        # Acceptable: argparse 0/2, or CONFIG_ERROR 30 if the venv lacks
        # gbm-mx-api (typical in CI). UNacceptable: rc=1 (crash) or a
        # traceback in stderr.
        self.assertNotEqual(rc, 1, f'Wrapper crashed (exit 1): {err!r}')
        self.assertNotIn('Traceback', err, f'Python traceback: {err!r}')
        self.assertNotIn('SyntaxError', err, f'Syntax error: {err!r}')

    def test_missing_credentials_returns_config_error(self):
        """Without GBM_EMAIL/GBM_PASSWORD, must exit EXIT_CONFIG_ERROR."""
        rc, _out, err = run_wrapper([])
        self.assertEqual(
            rc, EXIT_CONFIG_ERROR,
            f'Expected EXIT_CONFIG_ERROR ({EXIT_CONFIG_ERROR}) when creds missing, '
            f'got {rc}. stderr={err!r}'
        )

    def test_invalid_totp_format_does_not_crash(self):
        """Wrapper must not crash with a malformed --totp argument."""
        rc, _out, err = run_wrapper(
            ['--totp', 'abc'],
            env_extra={'GBM_EMAIL': 'fake@example.com', 'GBM_PASSWORD': 'fake'},
            timeout=5,
        )
        self.assertNotEqual(rc, 1, f'Wrapper crashed (exit 1). stderr={err!r}')
        self.assertNotIn('Traceback', err, f'Python traceback leaked: {err!r}')

    def test_full_flag_accepted(self):
        """--full + missing creds → config_error (not 'unrecognized arg')."""
        rc, _out, err = run_wrapper(['--full'])
        self.assertEqual(rc, EXIT_CONFIG_ERROR)
        self.assertNotIn('unrecognized', err.lower(),
                         f'--full was rejected by argparse: {err!r}')

    def test_exit_codes_match_php(self):
        """Every EXIT_* constant here should appear in GbmService.php or its
        BaseOwnCloudService parent (where the shared exit-code block now lives
        after the v0.14.17 base-class refactor)."""
        text = ''
        for fname in ('GbmService.php', 'BaseOwnCloudService.php'):
            php = REPO_ROOT / 'lib' / 'Service' / fname
            if php.is_file():
                text += php.read_text()
        for name in ('EXIT_OK', 'EXIT_MFA_REQUIRED', 'EXIT_MFA_INVALID',
                     'EXIT_AUTH_FAILED', 'EXIT_API_ERROR', 'EXIT_CONFIG_ERROR'):
            self.assertIn(name, text,
                          f'{name} missing from GbmService.php / BaseOwnCloudService.php — wrapper/PHP drift')


if __name__ == '__main__':
    unittest.main()
