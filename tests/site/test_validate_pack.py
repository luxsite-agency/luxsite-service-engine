"""bin/site validate: пак із symlink будь-де поза .git — відмова, ціль лінка не читається (рев'ю engine-ops PR #2, 27.11.2026).
Дія: validate паку з лінком → наслідок: exit ≠ 0, у повідомленні шлях лінка і «символьне посилання», без вмісту файла сервера."""
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class ValidatePackSymlinks(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp(prefix="site-pack-link."))
        self.pack = self.tmp / "pack"
        shutil.copytree(ROOT / "tests/site/pack-demo", self.pack, symlinks=True)

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def validate(self):
        env = {**os.environ, "SITES_FILE": "tests/site/sites.yaml"}
        return subprocess.run(["bin/site", "validate", str(self.pack)], cwd=str(ROOT), env=env, capture_output=True, text=True)

    def test_clean_pack_passes(self):
        r = self.validate()
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)

    def test_symlink_anywhere_is_refused_without_reading_target(self):
        secret = self.tmp / "secret.txt"
        secret.write_text("TOP-SECRET-MARKER-9f3a\n")
        cases = {"config/parameters.yaml": secret, "themes/demo/theme.css": secret, "templates/partials/evil.html.twig": secret,
                 "translations/messages.uk.yaml": secret, "content/dir": self.tmp, "broken.yaml": self.tmp / "nonexistent"}
        for rel, target in cases.items():
            with self.subTest(rel=rel):
                p = self.pack / rel
                p.parent.mkdir(parents=True, exist_ok=True)
                if p.exists() or p.is_symlink():
                    p.unlink()
                p.symlink_to(target)
                r = self.validate()
                self.assertNotEqual(r.returncode, 0, "пак із symlink мав бути відкинутий")
                self.assertIn("символьне посилання", r.stdout + r.stderr)
                self.assertIn(rel, r.stdout + r.stderr)
                self.assertNotIn("TOP-SECRET-MARKER-9f3a", r.stdout + r.stderr, "вміст цілі лінка не має потрапити у вивід")
                p.unlink()
                if rel == "config/parameters.yaml":
                    shutil.copy(ROOT / "tests/site/pack-demo" / rel, p)


if __name__ == "__main__":
    unittest.main()
