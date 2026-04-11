from pathlib import Path
from datetime import datetime
import shutil
import re

FILES = [
    "contact.php",
    "login.php",
    "walker-login.php",
    "memberships.php",
    "book-service.php",
    "admin.php",
]

STANDARD_TOP = "<?php\ndeclare(strict_types=1);\n\nrequire_once __DIR__ . '/includes/bootstrap.php';\n"

BACKUP_DIR = Path("backups") / f"flagged-pages-fix-{datetime.now().strftime('%Y%m%d-%H%M%S')}"
BACKUP_DIR.mkdir(parents=True, exist_ok=True)

def clean_first_chunk(text: str) -> str:
    text = text.lstrip("\ufeff")
    lines = text.splitlines(keepends=True)
    head = "".join(lines[:220])
    tail = "".join(lines[220:])

    # Remove top/bootstrap/session duplicates in the early part of the file
    head = re.sub(r'^\s*<\?php\s*\n?', '', head, count=1, flags=re.MULTILINE)
    head = re.sub(r'^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*\n?', '', head, flags=re.MULTILINE)
    head = re.sub(r'^\s*require_once\s+__DIR__\s*\.\s*[\'"]/includes/bootstrap\.php[\'"]\s*;\s*\n?', '', head, flags=re.MULTILINE)
    head = re.sub(r'^\s*require_once\s+__DIR__\s*\.\s*[\'"]/includes/security-headers\.php[\'"]\s*;\s*\n?', '', head, flags=re.MULTILINE)
    head = re.sub(r'^\s*session_start\s*\(\s*\)\s*;\s*\n?', '', head, flags=re.MULTILINE)

    # Remove old cookie/session setup blocks from the early part of the file
    head = re.sub(r'session_set_cookie_params\s*\(\s*\[[\s\S]*?\]\s*\)\s*;\s*', '', head, flags=re.MULTILINE)
    head = re.sub(r'^\s*ini_set\s*\(\s*[\'"]session\.[^;]+;\s*\n?', '', head, flags=re.MULTILINE)

    cleaned = head.lstrip("\n") + tail
    return STANDARD_TOP + "\n" + cleaned.lstrip("\n")

changed = []

for name in FILES:
    path = Path(name)
    if not path.exists():
        print(f"Skipped missing file: {name}")
        continue

    original = path.read_text(encoding="utf-8", errors="ignore")
    updated = clean_first_chunk(original)

    if updated != original:
        shutil.copy2(path, BACKUP_DIR / path.name)
        path.write_text(updated, encoding="utf-8")
        changed.append(name)

print("Updated:")
for name in changed:
    print(f" - {name}")

print(f"\nBackups saved to: {BACKUP_DIR}")