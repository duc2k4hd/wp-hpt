#!/usr/bin/env python3
"""Safely apply/check/rollback the exact WooCommerce 10.3.5 source patch."""

from __future__ import annotations

import argparse
import hashlib
import re
import sys
import zipfile
from pathlib import Path


PATCH_ROOT = Path(__file__).resolve().parent
PROJECT_ROOT = PATCH_ROOT.parent
BASELINE_VERSION = "10.3.5"
BASELINE_ZIP_SHA256 = "c24611f04e9e0ba993fd594aa299aa478bc1a3b198d2ebd5531cb1cec3125a55"
SOURCE_ZIP = Path("wp-content/plugins/woocommerce.zip")


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def plugin_version(plugin_root: Path) -> str | None:
    main = plugin_root / "woocommerce.php"
    if not main.is_file():
        return None
    match = re.search(r"^\s*\* Version:\s*([^\r\n]+)", main.read_text(encoding="utf-8", errors="replace"), re.M)
    return match.group(1).strip() if match else None


def source_version(archive: zipfile.ZipFile) -> str | None:
    try:
        text = archive.read("woocommerce/woocommerce.php").decode("utf-8", errors="replace")
    except KeyError:
        return None
    match = re.search(r"^\s*\* Version:\s*([^\r\n]+)", text, re.M)
    return match.group(1).strip() if match else None


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    operation = parser.add_mutually_exclusive_group()
    operation.add_argument("--check", action="store_true", help="read-only verification (default)")
    operation.add_argument("--apply", action="store_true", help="apply the patch after verifying every input file")
    operation.add_argument("--rollback", action="store_true", help="restore the pristine files from the WooCommerce ZIP")
    parser.add_argument("--root", type=Path, default=PROJECT_ROOT, help="WordPress project root (default: repository workspace)")
    args = parser.parse_args()

    project = args.root.resolve()
    plugin = project / "wp-content/plugins/woocommerce"
    source_zip = project / SOURCE_ZIP
    payload = PATCH_ROOT / "payload"
    if not source_zip.is_file():
        print(f"ERROR: pristine source archive not found: {source_zip}", file=sys.stderr)
        return 2

    archive_hash = sha256(source_zip.read_bytes())
    if archive_hash != BASELINE_ZIP_SHA256:
        print(
            f"REFUSED: source archive SHA-256 is {archive_hash}, not the audited {BASELINE_ZIP_SHA256}.",
            file=sys.stderr,
        )
        return 3

    actual_version = plugin_version(plugin)
    if actual_version != BASELINE_VERSION:
        print(
            f"REFUSED: installed WooCommerce version is {actual_version!r}; this patch is pinned to {BASELINE_VERSION}. "
            "Review/rebase and test the patch against the new release before applying it.",
            file=sys.stderr,
        )
        return 3

    with zipfile.ZipFile(source_zip) as archive:
        archived_version = source_version(archive)
        if archived_version != BASELINE_VERSION:
            print(f"REFUSED: bundled source ZIP version is {archived_version!r}, expected {BASELINE_VERSION}.", file=sys.stderr)
            return 3

        targets = []
        patch_files = sorted(
            path
            for path in payload.rglob("*")
            if path.is_file() and path.suffix.lower() in {".php", ".js"}
        )
        for patch_file in patch_files:
            relative = patch_file.relative_to(payload).as_posix()
            plugin_relative = "woocommerce/" + relative
            target = plugin / relative
            patched = patch_file.read_bytes()
            try:
                pristine = archive.read(plugin_relative)
            except KeyError:
                pristine = None
            if pristine is not None and not target.is_file():
                print(f"CONFLICT: expected upstream file is missing: {target}", file=sys.stderr)
                return 4
            if pristine is None and target.exists() and sha256(target.read_bytes()) != sha256(patched):
                print(f"CONFLICT: unexpected pre-existing file: {target}", file=sys.stderr)
                return 4
            current = target.read_bytes() if target.exists() else None
            allowed = {sha256(patched)}
            if pristine is not None:
                allowed.add(sha256(pristine))
            if current is not None and sha256(current) not in allowed:
                print(f"CONFLICT: {target} differs from both the clean 10.3.5 source and this patch.", file=sys.stderr)
                return 4
            if args.rollback and current is not None and sha256(current) != sha256(patched):
                print(f"REFUSED: {target} has local changes; the rollback will not overwrite them.", file=sys.stderr)
                return 4
            targets.append((target, patch_file, pristine, current))

    if args.check or (not args.apply and not args.rollback):
        for target, _patch, pristine, current in targets:
            state = "patched" if current is not None and sha256(current) == sha256(_patch.read_bytes()) else "clean" if pristine is not None else "new patch file"
            print(f"OK: {state}: {target}")
        print(f"WooCommerce {BASELINE_VERSION}: all {len(targets)} files are conflict-free.")
        return 0

    changed = 0
    for target, patch_file, pristine, current in targets:
        if args.apply:
            if current is not None and sha256(current) == sha256(patch_file.read_bytes()):
                continue
            target.parent.mkdir(parents=True, exist_ok=True)
            temporary = target.with_name(target.name + ".hpt-patch-tmp")
            temporary.write_bytes(patch_file.read_bytes())
            temporary.replace(target)
            print(f"APPLIED: {target}")
        else:
            if current is None or pristine is None:
                target.unlink(missing_ok=True)
                print(f"REMOVED: {target}")
            else:
                temporary = target.with_name(target.name + ".hpt-patch-tmp")
                temporary.write_bytes(pristine)
                temporary.replace(target)
                print(f"RESTORED: {target}")
        changed += 1

    print(f"Done: {changed} file(s) changed. No database data or plugin update settings were changed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
