#!/usr/bin/env python3
"""Validate compass files: check paths exist, flag stale files, report uncovered dirs."""

import os
import re
import sys
from datetime import datetime, timedelta
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
COMPASS_DIR = REPO_ROOT / "context" / "compass"
STALE_DAYS = 30

EXPECTED_DIRS = [
    "app/Http/Controllers",
    "app/Models",
    "app/Services",
    "app/Console/Commands",
    "database/migrations",
    "frontend/src",
    "routes",
]


def extract_paths(text: str) -> list[str]:
    """Extract backticked file paths from markdown text."""
    paths = []
    for match in re.finditer(r"`(app/[^`]+|routes/[^`]+|database/[^`]+|frontend/[^`]+|config/[^`]+)`", text):
        paths.append(match.group(1))
    for match in re.finditer(r"^- `([^`]+)`", text, re.MULTILINE):
        path = match.group(1)
        if "/" in path or path.endswith(".php") or path.endswith(".tsx") or path.endswith(".ts"):
            paths.append(path)
    return list(set(paths))


def extract_validated_date(text: str) -> datetime | None:
    """Extract 'Last validated: YYYY-MM-DD' date."""
    match = re.search(r"Last validated:\s*(\d{4}-\d{2}-\d{2})", text)
    if match:
        return datetime.strptime(match.group(1), "%Y-%m-%d")
    return None


def main():
    errors = []
    warnings = []

    if not COMPASS_DIR.exists():
        print(f"ERROR: {COMPASS_DIR} does not exist")
        sys.exit(1)

    compass_files = sorted(COMPASS_DIR.glob("*.md"))
    if not compass_files:
        print(f"ERROR: No compass files found in {COMPASS_DIR}")
        sys.exit(1)

    all_mentioned_dirs = set()
    now = datetime.now()
    stale_threshold = now - timedelta(days=STALE_DAYS)

    for compass_file in compass_files:
        text = compass_file.read_text()
        rel_name = compass_file.relative_to(REPO_ROOT)

        paths = extract_paths(text)
        for path in paths:
            full_path = REPO_ROOT / path
            if not full_path.exists():
                errors.append(f"  BROKEN PATH: {rel_name} references `{path}` (not found)")
            top_dir = path.split("/")[0]
            all_mentioned_dirs.add(top_dir)
            if "/" in path:
                parts = path.split("/")
                for depth in range(2, min(len(parts), 4)):
                    all_mentioned_dirs.add("/".join(parts[:depth]))

        validated = extract_validated_date(text)
        if validated is None:
            warnings.append(f"  MISSING DATE: {rel_name} has no 'Last validated' date")
        elif validated < stale_threshold:
            days_old = (now - validated).days
            warnings.append(f"  STALE: {rel_name} last validated {days_old} days ago ({validated.date()})")

    for expected in EXPECTED_DIRS:
        if expected not in all_mentioned_dirs:
            warnings.append(f"  NO COVERAGE: `{expected}/` not referenced in any compass file")

    print(f"Scanned {len(compass_files)} compass files\n")

    if errors:
        print(f"ERRORS ({len(errors)}):")
        for e in errors:
            print(e)
        print()

    if warnings:
        print(f"WARNINGS ({len(warnings)}):")
        for w in warnings:
            print(w)
        print()

    if not errors and not warnings:
        print("All checks passed.")

    sys.exit(1 if errors else 0)


if __name__ == "__main__":
    main()
