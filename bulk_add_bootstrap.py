from pathlib import Path
from datetime import datetime
import shutil
import re

ROOT = Path(".").resolve()
BACKUP_DIR = ROOT / "backups" / f"bootstrap-bulk-{datetime.now().strftime('%Y%m%d-%H%M%S')}"
BACKUP_DIR.mkdir(parents=True, exist_ok=True)

SKIP_FILES = {
    "db.php",
}

STANDARD_TOP = "<?php\ndeclare(strict_types=1);\n\nrequire_once __DIR__ . '/includes/bootstrap.php';\n"

def clean_top_of_file(text: str) -> str:
    text = text.lstrip("\ufeff")

    if not text.lstrip().startswith("<?php"):
        return text

    lines = text.splitlines(keepends=True)
    head = "".join(lines[:80])
    tail = "".join(lines[80:])

    head = re.sub(r'^\s*<\?php\s*\n?', '', head, count=1, flags=re.MULTILINE)
    head = re.sub(r'^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*\n?', '', head, flags=re.MULTILINE)
    head = re.sub(r'^\s*require_once\s+__DIR__\s*\.\s*[\'"]\/includes\/bootstrap\.php[\'"]\s*;\s*\n?', '', head, flags=re.MULTILINE)
    head = re.sub(r'^\s*require_once\s+__DIR__\s*\.\s*[\'"]\/includes\/security-headers\.php[\'"]\s*;\s*\n?', '', head, flags=re.MULTILINE)
    head = re.sub(r'^\s*session_start\s*\(\s*\)\s*;\s*\n?', '', head, flags=re.MULTILINE)

    return STANDARD_TOP + head.lstrip("\n") + tail

changed = []

for path in sorted(ROOT.glob("*.php")):
    if path.name in SKIP_FILES:
        continue

    original = path.read_text(encoding="utf-8", errors="ignore")
    updated = clean_top_of_file(original)

    if updated != original:
        shutil.copy2(path, BACKUP_DIR / path.name)
        path.write_text(updated, encoding="utf-8")
        changed.append(path.name)

print("Updated files:")
for name in changed:
    print(f" - {name}")

print(f"\nBackups saved to: {BACKUP_DIR}")