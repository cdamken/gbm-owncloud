#!/usr/bin/env python3
"""
verify_dom_ids.py — detect IDs referenced in JS but not defined in any
template (the bug that cost us hours on 2026-06-05).

Runs:
  $ python3 scripts/verify_dom_ids.py
  → exit 0 if everything is wired correctly
  → exit 1 if any JS reference points to a missing ID, with a list

How:
  1. Walk `templates/*.php` and collect every `id="xxx"` attribute.
  2. Walk `js/*.js` and collect every `$('xxx')` and `getElementById('xxx')`.
  3. Diff: any ID in (2) that's not in (1) is a bug — at runtime it returns
     null and `null.addEventListener / .classList / .value` throws,
     aborting the rest of the JS callback (as happened with `settings-btn`
     in dashboard.js DOMContentLoaded — aborting the wire-up of the TOTP
     submit button).

Designed to be cheap (<100ms on the whole repo) so it can run as a
mandatory check inside scripts/deploy.sh.
"""
from __future__ import annotations
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# A handful of IDs are provided by ownCloud core / theme, not by our templates.
# We don't define them — but referencing them is legitimate.
ALLOWLIST_CORE_IDS = {
    'body-user',     # ownCloud sets this on <body> for logged-in users
    'content',       # ownCloud core layout
    'app-content',   # ownCloud core layout
    'header',        # ownCloud core header
}

# IDs that the *JS* injects at runtime (via injectTopBar, injectStalenessChip,
# injectSharedChromeHtml, etc.). They don't live in any *.php source but DO
# exist in the DOM by the time other code references them.
ALLOWLIST_JS_INJECTED = {
    'update-btn',          # injected by js/_shared.js::injectTopBar (also in main.php for portfolio page)
    'last-update-age',     # staleness chip — injected by update_flow.js / dashboard.js
    'toast',               # injected by injectSharedChromeHtml (or in main.php)
    'toast-close-btn',
    'toast-title',
    'progress-bar',
    'progress-stage',
    # add any future runtime-injected IDs here, with a comment about who injects them
}


def collect_template_ids() -> dict[str, list[Path]]:
    """All id="..." values found in any templates/*.php file, with their source paths."""
    ids: dict[str, list[Path]] = {}
    for php in (ROOT / 'templates').glob('*.php'):
        text = php.read_text(encoding='utf-8')
        # Match id="foo" or id='foo' — PHP echo of dynamic IDs is rare in our templates
        for m in re.finditer(r"""\bid=["']([a-zA-Z][\w-]*)["']""", text):
            ids.setdefault(m.group(1), []).append(php)
    return ids


def collect_js_refs() -> dict[str, list[tuple[Path, int]]]:
    """All `$('foo')` / `getElementById('foo')` references in js/*.js, with file + line."""
    refs: dict[str, list[tuple[Path, int]]] = {}
    # Two patterns:
    #   $('foo')             — our local helper (most common)
    #   getElementById('foo') — vanilla
    patterns = [
        re.compile(r"""\$\(\s*["']([a-zA-Z][\w-]*)["']\s*\)"""),
        re.compile(r"""getElementById\s*\(\s*["']([a-zA-Z][\w-]*)["']\s*\)"""),
    ]
    # Strip line-comments (//...) before matching so a reference in a code
    # comment doesn't trigger a false-positive. Block comments /* ... */
    # we strip with a small state machine so we don't drop string literals
    # that contain "/*".
    def strip_comments(src: str) -> str:
        out = []
        i = 0
        in_block = False
        in_str = None  # quote char if inside a string, else None
        while i < len(src):
            c = src[i]
            nxt = src[i+1] if i+1 < len(src) else ''
            if in_block:
                if c == '*' and nxt == '/':
                    in_block = False
                    out.append('  ')  # preserve column positions
                    i += 2
                else:
                    out.append(' ' if c != '\n' else '\n')
                    i += 1
                continue
            if in_str:
                out.append(c)
                if c == '\\':
                    if i+1 < len(src):
                        out.append(src[i+1])
                        i += 2
                        continue
                elif c == in_str:
                    in_str = None
                i += 1
                continue
            if c in ('"', "'", '`'):
                in_str = c
                out.append(c)
                i += 1
                continue
            if c == '/' and nxt == '/':
                # line comment — skip to end of line
                while i < len(src) and src[i] != '\n':
                    i += 1
                continue
            if c == '/' and nxt == '*':
                in_block = True
                i += 2
                continue
            out.append(c)
            i += 1
        return ''.join(out)

    for js in (ROOT / 'js').rglob('*.js'):
        if 'vendor/' in str(js):
            continue  # skip Chart.js etc.
        raw = js.read_text(encoding='utf-8')
        cleaned = strip_comments(raw)
        for lineno, line in enumerate(cleaned.splitlines(), 1):
            for pat in patterns:
                for m in pat.finditer(line):
                    refs.setdefault(m.group(1), []).append((js, lineno))
    return refs


def main() -> int:
    templates_dir = ROOT / 'templates'
    if not templates_dir.exists():
        print(f'ERROR: {templates_dir} not found — is this an ownCloud app repo?',
              file=sys.stderr)
        return 2

    template_ids = collect_template_ids()
    js_refs = collect_js_refs()

    defined = set(template_ids) | ALLOWLIST_CORE_IDS | ALLOWLIST_JS_INJECTED
    referenced = set(js_refs)

    missing = sorted(referenced - defined)
    unused = sorted(defined - referenced - ALLOWLIST_CORE_IDS - ALLOWLIST_JS_INJECTED)

    print('=' * 70)
    print('DOM-ID sync check')
    print('=' * 70)
    print(f'  Templates scanned:    {len(list(templates_dir.glob("*.php")))} .php files')
    print(f'  IDs defined in HTML:  {len(template_ids)}')
    print(f'  IDs referenced in JS: {len(referenced)}')
    print()

    if missing:
        print(f'❌ FAIL: {len(missing)} ID(s) referenced from JS but not defined anywhere:')
        for mid in missing:
            print(f'\n  • {mid!r}')
            for path, lineno in js_refs[mid][:5]:
                rel = path.relative_to(ROOT)
                print(f'      {rel}:{lineno}')
            if len(js_refs[mid]) > 5:
                print(f'      ... and {len(js_refs[mid]) - 5} more')
        print()
        print('Each of these will throw at runtime — and because JS aborts the')
        print('rest of its current event-loop tick on uncaught errors, the')
        print('listeners wired up AFTER the failing line will never attach.')
        print('Fix either by adding the ID to the template, removing the JS')
        print('reference, or wrapping the call in a null-safe helper.')
        return 1

    print('✅ PASS: every JS reference resolves to a known ID')
    if unused:
        print()
        print(f'(info) {len(unused)} template ID(s) defined but never referenced in JS '
              '— likely dead markup, not a bug:')
        for u in unused[:10]:
            print(f'  • {u}')
        if len(unused) > 10:
            print(f'  ... and {len(unused) - 10} more')

    return 0


if __name__ == '__main__':
    sys.exit(main())
