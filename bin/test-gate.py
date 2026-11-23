#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
"""test-gate — одна перевірка правил тестування: ноутбук, сервер розробки, CI, колеги.

Лише стандартний Python 3.9+, без мережі. Правила — testing-rules.md.

  python test-gate.py check [--base REF] [--head SHA] [--json]   зміни гілки проти бази (без --head: разом з робочою копією)
                            [--advisory]                         лише попередити (вихід 0); у GitHub Actions — анотації в PR
  python test-gate.py audit [--json]                             усі тести репо: скільки з дією, скільки «сторінка відкрилась»
  python test-gate.py run <рівень> [--via ІМ'Я] [--print]        запуск тестів командою з testgate.json
  python test-gate.py init [--strict]                            новий проєкт: копія скрипта й тестів, testgate.json, CI, хук
  python test-gate.py install-hook | uninstall-hook              pre-push хук у цьому клоні
  python test-gate.py hook <remote> <url>                        викликає сам хук

Вихід: 0 — гаразд, 1 — порушення, 2 — помилка запуску.
Виняток: рядок у повідомленні коміту гілки «Tests: none — причина».
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from pathlib import Path

VERSION = "1.2.0"
MARK = "test-gate pre-push"
CONFIG = "testgate.json"

DEFAULTS = {
    "base": None,
    "hard_ext": [".php", ".py", ".js", ".jsx", ".ts", ".tsx", ".vue", ".go", ".rb"],
    "soft_ext": [".twig", ".html", ".css", ".scss", ".less"],
    "skip_dirs": ["vendor", "node_modules", "var", "migrations", "public", "docs", "dist", "build", ".github"],
    "test_dirs": ["tests", "test", "__tests__", "e2e", "spec", "specs"],
    "fix_branch": r"^(fix|bugfix|hotfix)[/_-]",
    "hook_remotes": ["origin"],
    "run": {},
    "exec": {},
    "via": None,
}

TEST_FILE = re.compile(r"(Test\.php|^test_.*\.py|_test\.py|\.(spec|test)\.[jt]sx?)$")
WAIVER = re.compile(r"(?im)^\s*tests?\s*:\s*none\b[\s—–:\-(]*(.{8,}?)\)?\s*$")
HUNK = re.compile(r"^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@")


class GateError(Exception):
    pass


def out(text=""):
    sys.stdout.write(text + "\n")


def git(repo, *args, check=True):
    r = subprocess.run(["git", "-c", "core.quotepath=off", *args], cwd=str(repo),
                       capture_output=True, text=True, encoding="utf-8", errors="replace")
    if check and r.returncode != 0:
        raise GateError("git %s: %s" % (" ".join(args), r.stderr.strip()))
    return r.stdout


def repo_root(start):
    try:
        return Path(git(start, "rev-parse", "--show-toplevel").strip())
    except OSError:
        raise GateError("нема такої теки: %s" % start)
    except GateError as e:
        reason = str(e).split("\n")[0]
        if "dubious ownership" in str(e):
            reason = "тека належить іншому користувачу — запускай від власника теки або додай її в safe.directory"
        raise GateError("не можу відкрити репозиторій %s (%s)" % (start, reason))


def load_config(repo):
    cfg = dict(DEFAULTS)
    path = Path(repo) / CONFIG
    if path.is_file():
        try:
            user = json.loads(path.read_text(encoding="utf-8"))
        except ValueError as e:
            raise GateError("%s: %s" % (CONFIG, e))
        for key, value in user.items():
            if key in ("skip_dirs", "test_dirs") and isinstance(value, list):
                cfg[key] = sorted(set(DEFAULTS[key]) | set(value))
            else:
                cfg[key] = value
    return cfg


# ---------- класифікація файлів ----------

def kind_of(path, cfg):
    """test | hard | soft | other"""
    p = Path(path)
    parts = [x.lower() for x in p.parts]
    in_tests = any(x in cfg["test_dirs"] for x in parts[:-1])
    if TEST_FILE.search(p.name):
        return "test"
    if in_tests:
        return "other"
    if any(x in cfg["skip_dirs"] for x in parts[:-1]):
        return "other"
    ext = p.suffix.lower()
    if ext in cfg["hard_ext"]:
        return "hard"
    if ext in cfg["soft_ext"]:
        return "soft"
    return "other"


# ---------- розбір тестових функцій ----------

def mask(src, php=False):
    """Вміст рядків і коментарів → пробіли (довжина та переноси збережені), щоб рахувати дужки."""
    res, i, n, state = list(src), 0, len(src), None
    while i < n:
        c, nxt = src[i], src[i + 1] if i + 1 < n else ""
        if state is None:
            if c in "'\"`":
                state = c
            elif c == "/" and nxt == "/":
                state = "line"; res[i] = " "
            elif c == "/" and nxt == "*":
                state = "block"; res[i] = " "
            elif php and c == "#" and nxt != "[":
                state = "line"; res[i] = " "
        elif state in "'\"`":
            if c == "\\":
                res[i] = " "
                if i + 1 < n and src[i + 1] != "\n":
                    res[i + 1] = " "
                i += 2
                continue
            if c == state:
                state = None
            elif c != "\n":
                res[i] = " "
        elif state == "line":
            if c == "\n":
                state = None
            else:
                res[i] = " "
        elif state == "block":
            if c == "*" and nxt == "/":
                res[i] = res[i + 1] = " "
                state = None
                i += 2
                continue
            if c != "\n":
                res[i] = " "
        i += 1
    return "".join(res)


def match_brace(masked, pos):
    depth = 0
    for i in range(pos, len(masked)):
        if masked[i] == "{":
            depth += 1
        elif masked[i] == "}":
            depth -= 1
            if depth == 0:
                return i
    return len(masked) - 1


def line_of(src, pos):
    return src.count("\n", 0, pos) + 1


def php_functions(src):
    masked, found = mask(src, php=True), []
    for m in re.finditer(r"\bfunction\s+(\w+)\s*\(", masked):
        name = m.group(1)
        before = src[max(0, m.start() - 400):m.start()]
        tail = before[before.rfind("}") + 1:]
        if not (name.lower().startswith("test") or re.search(r"#\[Test\b|@test\b", tail)):
            continue
        brace = semi = -1
        for i in range(m.end(), len(masked)):
            if masked[i] == "{":
                brace = i; break
            if masked[i] == ";":
                semi = i; break
        if brace < 0 or semi >= 0:
            continue
        end = match_brace(masked, brace)
        found.append((name, line_of(src, m.start()), line_of(src, end), src[brace:end + 1]))
    return found


def js_functions(src):
    masked, found = mask(src), []
    for m in re.finditer(r"(?<![\w.])(?:test|it)(?:\.(?:only|skip|fixme))?\s*\(", masked):
        title = re.match(r"\s*(['\"`])(.{0,80}?)\1", src[m.end():])
        body = re.compile(r"=>\s*\{|\bfunction\b[^{(]*\([^)]*\)\s*\{").search(masked, m.end())
        if not body:
            continue
        brace = body.end() - 1
        end = match_brace(masked, brace)
        name = title.group(2) if title else "test@%d" % line_of(src, m.start())
        found.append((name, line_of(src, m.start()), line_of(src, end), src[brace:end + 1]))
    return found


def py_functions(src):
    lines, found, i = src.split("\n"), [], 0
    head = re.compile(r"^([ \t]*)(?:async[ \t]+)?def[ \t]+(test\w*)[ \t]*\(")
    while i < len(lines):
        m = head.match(lines[i])
        if not m:
            i += 1
            continue
        indent, start, depth, j = len(m.group(1).expandtabs(4)), i, 0, i
        while j < len(lines):
            code = lines[j].split("#")[0]
            depth += code.count("(") + code.count("[") - code.count(")") - code.count("]")
            if depth <= 0 and code.rstrip().endswith(":"):
                break
            j += 1
        end = j
        for k in range(j + 1, len(lines)):
            raw = lines[k]
            if not raw.strip() or raw.lstrip().startswith("#"):
                continue
            if len(raw.expandtabs(4)) - len(raw.expandtabs(4).lstrip()) <= indent:
                break
            end = k
        found.append((m.group(2), start + 1, end + 1, "\n".join(lines[j + 1:end + 1])))
        i = end + 1
    return found


def functions_of(path, src):
    name = Path(path).name.lower()
    if name.endswith(".php"):
        return php_functions(src), "php"
    if name.endswith(".py"):
        return py_functions(src), "py"
    return js_functions(src), "js"


ACTION = {
    "php": re.compile(r"->\s*request\(\s*['\"](?:POST|PUT|PATCH|DELETE)['\"]|->\s*(?:submit|submitForm|click|clickLink|"
                      r"post|postJson|put|putJson|patch|patchJson|delete|deleteJson|press|type|select|attach|check|"
                      r"uncheck|fill|execute|dispatch|handle|run)\s*\("),
    "py": re.compile(r"\.(?:click|dblclick|fill|press|type|check|uncheck|select_option|set_input_files|drag_to|tap|"
                     r"post|put|patch|delete|submit|invoke)\s*\(|call_command\s*\(|subprocess\."),
    "js": re.compile(r"\.(?:click|dblclick|fill|press|type|check|uncheck|selectOption|setInputFiles|dragTo|tap|"
                     r"post|put|patch|delete|submit)\s*\("),
}
PHP_STATUS = {"assertResponseIsSuccessful", "assertOk", "assertSuccessful"}
PHP_PRESENCE = {"assertSelectorExists", "assertPageTitleContains", "assertPageTitleSame", "assertResponseHasHeader"}
PHP_CODE = {"assertResponseStatusCodeSame", "assertStatus", "assertStatusCode"}
OK200 = re.compile(r"\b200\b|HTTP_OK")


def asserts_of(body, lang):
    """→ (strong, presence, status) кількість перевірок за силою."""
    strong = presence = status = 0
    if lang == "php":
        for m in re.finditer(r"\b(assert\w+)\s*\(([^;]{0,160})", body):
            name, arg = m.group(1), m.group(2)
            if name in PHP_STATUS or (name in PHP_CODE and OK200.match(arg.strip())):
                status += 1
            elif "getStatusCode()" in arg and OK200.search(arg):
                status += 1
            elif name == "assertTrue" and re.search(r"isSuccessful\(\)|isOk\(\)", arg):
                status += 1
            elif name in PHP_PRESENCE:
                presence += 1
            else:
                strong += 1
        strong += len(re.findall(r"\bexpect\w*\s*\(|->\s*expects\s*\(|shouldReceive\s*\(|seeInDatabase\s*\(", body))
        return strong, presence, status
    if lang == "py":
        for line in body.split("\n"):
            code = line.split("#")[0]
            if not re.search(r"^\s*assert\b|\.assert\w+\s*\(|\bexpect\s*\(|\braises\s*\(", code):
                continue
            if re.search(r"==\s*200\b|\.ok\b", code) or (re.search(r"\b200\b", code)
                                                        and re.search(r"\bstatus(_code)?\b", code)):
                status += 1
            elif re.search(r"to_have_title|to_have_url|to_be_visible|to_be_attached|is_visible\(\)\s*$", code):
                presence += 1
            else:
                strong += 1
        return strong, presence, status
    for m in re.finditer(r"\bexpect\s*\(", body):
        stmt = body[m.start():m.start() + 240].split(";")[0]
        if re.search(r"toBeOK|to(?:Be|Equal|StrictEqual)\(\s*200\s*\)", stmt):
            status += 1
        elif re.search(r"toHaveTitle|toHaveURL|toBeVisible|toBeAttached", stmt):
            presence += 1
        else:
            strong += 1
    strong += len(re.findall(r"\bassert\.\w+\s*\(", body))
    return strong, presence, status


def verdict_of(body, lang):
    """ok | weak (дія є, перевірено лише статус) | smoke (без дії, лише статус чи наявність) | noassert"""
    action = bool(ACTION[lang].search(body))
    strong, presence, status = asserts_of(body, lang)
    if lang == "php" and "expectNotToPerformAssertions" in body:
        return "ok"
    if strong:
        return "ok"
    if not (presence or status):
        return "noassert"
    if action:
        return "ok" if presence else "weak"
    return "smoke"


LABEL = {
    "smoke": "лише «сторінка відкрилась» (статус чи наявність елемента, без дії)",
    "weak": "дія є, але перевірено лише статус відповіді, не наслідок",
    "noassert": "нема жодної перевірки",
}


# ---------- перевірка гілки ----------

def resolve_base(repo, cfg, override=None):
    head_ref = git(repo, "symbolic-ref", "-q", "--short", "refs/remotes/origin/HEAD", check=False).strip()
    for cand in (override, cfg.get("base"), head_ref, "origin/main", "origin/master", "main", "master"):
        if cand and subprocess.run(["git", "rev-parse", "-q", "--verify", cand + "^{commit}"], cwd=str(repo),
                                   capture_output=True).returncode == 0:
            return cand
    raise GateError("не знайшов базову гілку: вкажи --base або «base» у " + CONFIG)


def added_lines(repo, mb, head, path):
    args = ["diff", "-U0", mb] + ([head] if head else []) + ["--", path]
    lines = set()
    for row in git(repo, *args).split("\n"):
        m = HUNK.match(row)
        if m:
            start, count = int(m.group(1)), int(m.group(2) if m.group(2) is not None else 1)
            lines.update(range(start, start + count))
    return lines


def check(repo, base=None, head=None, branch=None):
    cfg = load_config(repo)
    base = resolve_base(repo, cfg, base)
    tip = head or "HEAD"
    mb = git(repo, "merge-base", base, tip).strip()
    if branch is None:
        branch = git(repo, "rev-parse", "--abbrev-ref", tip, check=False).strip() or tip[:10]

    changed = []
    args = ["diff", "--name-status", "-M", mb] + ([head] if head else [])
    for row in git(repo, *args).split("\n"):
        cols = row.split("\t")
        if len(cols) >= 2 and not cols[0].startswith("D"):
            changed.append((cols[0][0], cols[-1]))
    untracked = set()
    if not head:
        untracked = {p for p in git(repo, "ls-files", "--others", "--exclude-standard").split("\n") if p}
        changed += [("A", p) for p in sorted(untracked)]

    kinds = {"test": [], "hard": [], "soft": [], "other": []}
    for _, path in changed:
        kinds[kind_of(path, cfg)].append(path)

    touched, new_count = [], 0
    for path in kinds["test"]:
        if path in untracked or not head:
            full = Path(repo) / path
            src = full.read_text(encoding="utf-8", errors="replace") if full.is_file() else ""
        else:
            src = git(repo, "show", "%s:%s" % (head, path), check=False)
        lines = None if path in untracked else added_lines(repo, mb, head, path)
        funcs, lang = functions_of(path, src)
        for name, start, end, body in funcs:
            if lines is not None and not any(start <= n <= end for n in lines):
                continue
            is_new = lines is None or start in lines
            new_count += is_new
            touched.append({"file": path, "name": name, "line": start, "new": bool(is_new),
                            "verdict": verdict_of(body, lang)})

    log = git(repo, "log", "--format=%B", "%s..%s" % (mb, tip), check=False)
    wm = WAIVER.search(log)
    waiver = wm.group(1).strip() if wm else None

    violations, warnings = [], []
    good = [t for t in touched if t["verdict"] == "ok"]
    if kinds["hard"]:
        if not kinds["test"]:
            violations.append(("no-tests", "код змінено (%d файл.), а тестів у гілці нема" % len(kinds["hard"])))
        elif touched and not good:
            violations.append(("no-action-test", "жоден зачеплений тест не робить дію з перевіркою наслідку"))
        elif not touched:
            warnings.append("файли тестів змінено, але жодної тестової функції не зачеплено")
        if re.search(cfg["fix_branch"], branch or "", re.I) and kinds["test"] and not new_count:
            violations.append(("fix-without-test", "гілка-виправлення без нового тесту, що відтворює баг"))
    elif kinds["soft"] and not kinds["test"]:
        warnings.append("змінено лише шаблони чи стилі (%d файл.) без тестів — перевір сторінку вручну або E2E"
                        % len(kinds["soft"]))
    for t in touched:
        if t["verdict"] != "ok":
            warnings.append("%s:%d %s — %s" % (t["file"], t["line"], t["name"], LABEL[t["verdict"]]))

    return {"version": VERSION, "repo": Path(repo).name, "branch": branch, "base": base, "merge_base": mb[:10],
            "files": {k: len(v) for k, v in kinds.items()}, "tests_touched": len(touched), "tests_new": new_count,
            "violations": [{"code": c, "message": m} for c, m in violations], "warnings": warnings,
            "waiver": waiver, "ok": not violations or bool(waiver)}


def github_report(res, advisory):
    """У GitHub Actions: анотації в PR і підсумок job. Поза Actions нічого не робить."""
    if os.environ.get("GITHUB_ACTIONS") != "true":
        return

    def esc(text):
        return text.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")

    blocking = [] if res["waiver"] else res["violations"]
    for v in blocking:
        out("::%s title=test-gate %s::%s" % ("warning" if advisory else "error", v["code"], esc(v["message"])))
    for w in res["warnings"][:10]:
        out("::notice title=test-gate::%s" % esc(w))
    path = os.environ.get("GITHUB_STEP_SUMMARY")
    if not path:
        return
    rows = ["### test-gate %s — %s" % (res["version"], "гаразд" if not blocking else
                                       ("попередження, злиття не блокує" if advisory else "порушення"))]
    rows += ["- **%s** — %s" % (v["code"], v["message"]) for v in res["violations"]]
    if res["waiver"]:
        rows.append("- виняток за комітом: %s" % res["waiver"])
    rows += ["- %s" % w for w in res["warnings"][:20]]
    if blocking:
        rows.append("")
        rows.append("Додай тест, який робить дію й перевіряє наслідок, або рядок у повідомленні коміту: "
                    "`Tests: none — причина`. Правила — розділ «Тести» в AGENTS.md.")
    with open(path, "a", encoding="utf-8") as fh:
        fh.write("\n".join(rows) + "\n")


def print_check(res, advisory=False):
    f = res["files"]
    out("test-gate %s · %s · %s проти %s (%s)" % (res["version"], res["repo"], res["branch"], res["base"],
                                                 res["merge_base"]))
    out("Код: %d файл. (+ шаблони й стилі %d) · тести: %d файл., функцій зачеплено %d, з них нових %d"
        % (f["hard"], f["soft"], f["test"], res["tests_touched"], res["tests_new"]))
    if res["violations"]:
        out("ВИНЯТОК ЗА КОМІТОМ (Tests: none — %s):" % res["waiver"] if res["waiver"] else "ПОРУШЕННЯ:")
        for v in res["violations"]:
            out("  [%s] %s" % (v["code"], v["message"]))
    if res["warnings"]:
        out("Попередження:")
        for w in res["warnings"][:20]:
            out("  " + w)
        if len(res["warnings"]) > 20:
            out("  … ще %d" % (len(res["warnings"]) - 20))
    if not res["ok"]:
        out("Що зробити: додай тест, який робить дію (форма, POST, команда, клік) і перевіряє наслідок (запис у базі,")
        out("лист, подія, редірект, текст). Справді нема чого тестувати — рядок у повідомленні коміту:")
        out("  Tests: none — причина")
        if advisory:
            out("Режим попередження: пуш і злиття не блокуються, але виправ це до приймання.")
    elif not res["violations"] and not res["warnings"]:
        out("Гаразд.")


# ---------- аудит усіх тестів ----------

def audit(repo):
    cfg = load_config(repo)
    skip = set(cfg["skip_dirs"]) - {"docs"} | {".git"}
    totals = {"ok": 0, "weak": 0, "smoke": 0, "noassert": 0}
    per_file = []
    for root, dirs, files in os.walk(str(repo)):
        dirs[:] = [d for d in dirs if d not in skip and not d.startswith(".")]
        for fname in files:
            if not TEST_FILE.search(fname):
                continue
            full = Path(root) / fname
            funcs, lang = functions_of(fname, full.read_text(encoding="utf-8", errors="replace"))
            counts = {"ok": 0, "weak": 0, "smoke": 0, "noassert": 0}
            for _, _, _, body in funcs:
                counts[verdict_of(body, lang)] += 1
            for k in totals:
                totals[k] += counts[k]
            if funcs:
                per_file.append((full.relative_to(repo).as_posix(), counts))
    return {"version": VERSION, "repo": Path(repo).name, "files": len(per_file), "functions": sum(totals.values()),
            "totals": totals,
            "worst": sorted(([p, c["smoke"] + c["noassert"] + c["weak"], sum(c.values())] for p, c in per_file
                             if c["smoke"] + c["noassert"] + c["weak"]), key=lambda x: -x[1])[:15]}


def print_audit(res):
    t = res["totals"]
    out("test-gate %s · аудит %s: %d файлів тестів, %d тестових функцій" % (res["version"], res["repo"], res["files"],
                                                                         res["functions"]))
    out("  з перевіркою наслідку: %d" % t["ok"])
    out("  лише «сторінка відкрилась»: %d" % t["smoke"])
    out("  дія без перевірки наслідку: %d" % t["weak"])
    out("  без жодної перевірки: %d" % t["noassert"])
    if res["worst"]:
        out("Де найбільше слабких тестів (слабких / усього):")
        for path, bad, total in res["worst"]:
            out("  %3d / %-3d %s" % (bad, total, path))


# ---------- запуск тестів ----------

def run_command(repo, level, via=None, wt=None):
    cfg = load_config(repo)
    spec = cfg["run"].get(level)
    if spec is None:
        raise GateError("у %s нема run.%s; доступні рівні: %s" % (CONFIG, level, ", ".join(sorted(cfg["run"])) or "—"))
    if isinstance(spec, str):
        spec = {"cmd": spec, "exec": True}
    python = '"%s"' % sys.executable if " " in sys.executable else sys.executable
    cmd = spec["cmd"].replace("{python}", python)
    via = via or os.environ.get("TESTGATE_VIA") or cfg.get("via")
    if spec.get("exec", True) and via:
        if via not in cfg["exec"]:
            raise GateError("у %s нема exec.%s; доступні: %s" % (CONFIG, via, ", ".join(sorted(cfg["exec"])) or "—"))
        tpl = cfg["exec"][via].replace("{wt}", wt or Path(repo).name)
        cmd = tpl.replace("{cmd}", cmd) if "{cmd}" in tpl else tpl + " " + cmd
    return cmd


# ---------- хук ----------

HOOK = """#!/bin/sh
# %s v%s — перевірка правил тестування перед пушем (testing-rules.md).
# Прибрати: python <test-gate.py> uninstall-hook
ROOT=$(git rev-parse --show-toplevel 2>/dev/null) || exit 0
GATE=""
for g in "$ROOT/bin/test-gate.py" "$ROOT/tools/test-gate.py" "%s"; do
  if [ -f "$g" ]; then GATE="$g"; break; fi
done
[ -n "$GATE" ] || { echo "test-gate: скрипт не знайдено, перевірку пропущено" >&2; exit 0; }
PY=""
for p in python3 python py; do
  if command -v "$p" >/dev/null 2>&1 && "$p" -c "import sys" >/dev/null 2>&1; then PY="$p"; break; fi
done
[ -n "$PY" ] || { echo "test-gate: Python не знайдено, перевірку пропущено" >&2; exit 0; }
exec "$PY" "$GATE" hook "$@"
"""


def hook_path(repo):
    custom = git(repo, "config", "--get", "core.hooksPath", check=False).strip()
    if custom:
        base = Path(custom) if Path(custom).is_absolute() else Path(repo) / custom
    else:
        common = Path(git(repo, "rev-parse", "--git-common-dir").strip())
        base = (common if common.is_absolute() else Path(repo) / common) / "hooks"
    return base / "pre-push"


def install_hook(repo):
    path = hook_path(repo)
    if path.exists() and MARK not in path.read_text(encoding="utf-8", errors="replace"):
        raise GateError("у %s уже є чужий pre-push хук — не перезаписую, додай виклик test-gate вручну" % path)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(HOOK % (MARK, VERSION, Path(__file__).resolve().as_posix()), encoding="utf-8", newline="\n")
    try:
        path.chmod(0o755)
    except OSError:
        pass
    return path


def uninstall_hook(repo):
    path = hook_path(repo)
    if path.exists() and MARK in path.read_text(encoding="utf-8", errors="replace"):
        path.unlink()
        return path
    return None


def run_hook(repo, remote, stdin_text):
    cfg = load_config(repo)
    if remote not in cfg["hook_remotes"]:
        return 0
    default = resolve_base(repo, cfg).split("/")[-1]
    code = 0
    for row in stdin_text.split("\n"):
        cols = row.split()
        if len(cols) < 4:
            continue
        local_ref, local_sha = cols[0], cols[1]
        if set(local_sha) == {"0"} or not local_ref.startswith("refs/heads/"):
            continue
        branch = local_ref[len("refs/heads/"):]
        if branch == default:
            continue
        res = check(repo, head=local_sha, branch=branch)
        if res["violations"] or res["warnings"]:
            print_check(res)
        if not res["ok"]:
            code = 1
    if code:
        out("test-gate: пуш зупинено. Правила — testing-rules.md.")
    return code


# ---------- новий проєкт ----------

WORKFLOW = """# Правила тестування (bin/test-gate.py): код без тестів, тести без перевірки наслідку, fix-гілка без нового тесту.
# Та сама перевірка, що в pre-push хуку; виняток — рядок коміту «Tests: none — причина».
# З прапорцем --advisory порушення видно анотацією, PR не блокується; без нього job падає.
name: test-gate

on:
  pull_request:

jobs:
  test-gate:
    runs-on: ubuntu-latest
    env:
      PR_BASE: origin/${{ github.base_ref }}
      PR_HEAD: ${{ github.event.pull_request.head.sha }}
      PR_BRANCH: ${{ github.head_ref }}
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0
          ref: ${{ github.event.pull_request.head.sha }}
      - name: Тести самої перевірки
        run: python3 -m unittest discover -s tests/tools
      - name: bin/test-gate.py check
        run: python3 bin/test-gate.py check__MODE__ --base "$PR_BASE" --head "$PR_HEAD" --branch "$PR_BRANCH"
"""


def init_repo(repo, strict=False):
    """Кладе в репозиторій усе потрібне: копію скрипта й тестів, testgate.json, workflow для CI, pre-push хук."""
    repo, me, done = Path(repo), Path(__file__).resolve(), []

    def put(src, dst, label):
        if src.resolve() == dst.resolve():
            return
        if dst.is_file() and dst.read_bytes() == src.read_bytes():
            done.append("%s: без змін" % label)
            return
        fresh = not dst.exists()
        dst.parent.mkdir(parents=True, exist_ok=True)
        dst.write_bytes(src.read_bytes())
        done.append("%s: %s" % (label, "додано" if fresh else "оновлено"))

    put(me, repo / "bin" / "test-gate.py", "bin/test-gate.py")
    for cand in (me.parent / "tests" / "test_test_gate.py", me.parents[1] / "tests" / "tools" / "test_test_gate.py"):
        if cand.is_file():
            put(cand, repo / "tests" / "tools" / "test_test_gate.py", "tests/tools/test_test_gate.py")
            break
    else:
        done.append("tests/tools/test_test_gate.py: тестів поруч зі скриптом нема, скопіюй вручну")

    cfg = repo / CONFIG
    if cfg.exists():
        done.append("%s: уже є, не чіпаю" % CONFIG)
    else:
        run = {}
        if (repo / "composer.json").is_file():
            run["all"] = "vendor/bin/phpunit"
        elif any((repo / n).is_file() for n in ("pyproject.toml", "pytest.ini", "setup.cfg", "requirements.txt")):
            run["all"] = {"cmd": "{python} -m pytest", "exec": False}
        elif (repo / "package.json").is_file():
            run["all"] = {"cmd": "npm test", "exec": False}
        run["gate"] = {"cmd": "{python} -m unittest discover -s tests/tools", "exec": False}
        cfg.write_text(json.dumps({"run": run, "exec": {}}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8",
                       newline="\n")
        done.append("%s: створено, перевір команди запуску тестів" % CONFIG)

    wf_dir = repo / ".github" / "workflows"
    known = [p for p in list(wf_dir.glob("*.yml")) + list(wf_dir.glob("*.yaml"))
             if "test-gate.py" in p.read_text(encoding="utf-8", errors="replace")] if wf_dir.is_dir() else []
    if known:
        done.append("CI: job уже є в %s" % known[0].name)
    else:
        wf_dir.mkdir(parents=True, exist_ok=True)
        (wf_dir / "test-gate.yml").write_text(WORKFLOW.replace("__MODE__", "" if strict else " --advisory"),
                                              encoding="utf-8", newline="\n")
        done.append("CI: .github/workflows/test-gate.yml (%s)" % ("блокує PR" if strict else "лише попереджає"))

    try:
        done.append("хук: %s" % install_hook(repo))
    except GateError as e:
        done.append("хук: не поставлено — %s" % e)
    return done


# ---------- CLI ----------

def main(argv=None, stdin_text=None):
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(encoding="utf-8", errors="replace")
        except (AttributeError, ValueError):
            pass
    ap = argparse.ArgumentParser(prog="test-gate", description="Перевірка правил тестування (testing-rules.md)")
    ap.add_argument("--version", action="version", version=VERSION)
    ap.add_argument("-C", dest="cwd", default=".", help="тека репозиторію (типово поточна)")
    sub = ap.add_subparsers(dest="cmd", required=True)
    c = sub.add_parser("check"); c.add_argument("--base"); c.add_argument("--head"); c.add_argument("--branch")
    c.add_argument("--json", action="store_true")
    c.add_argument("--advisory", action="store_true", help="лише попередити: порушення показати, вихід 0")
    a = sub.add_parser("audit"); a.add_argument("--json", action="store_true")
    r = sub.add_parser("run"); r.add_argument("level"); r.add_argument("--via"); r.add_argument("--wt")
    r.add_argument("--print", dest="show", action="store_true")
    sub.add_parser("install-hook"); sub.add_parser("uninstall-hook")
    n = sub.add_parser("init"); n.add_argument("--strict", action="store_true", help="job у CI блокує PR, а не попереджає")
    h = sub.add_parser("hook"); h.add_argument("remote", nargs="?", default="origin"); h.add_argument("url", nargs="?")
    args = ap.parse_args(argv)
    try:
        repo = repo_root(args.cwd)
        if args.cmd == "check":
            res = check(repo, args.base, args.head, args.branch)
            out(json.dumps(res, ensure_ascii=False, indent=1)) if args.json else print_check(res, args.advisory)
            github_report(res, args.advisory)
            return 0 if res["ok"] or args.advisory else 1
        if args.cmd == "audit":
            res = audit(repo)
            out(json.dumps(res, ensure_ascii=False, indent=1)) if args.json else print_audit(res)
            return 0
        if args.cmd == "run":
            cmd = run_command(repo, args.level, args.via, args.wt)
            out("test-gate run %s: %s" % (args.level, cmd))
            return 0 if args.show else subprocess.call(cmd, shell=True, cwd=str(repo))
        if args.cmd == "init":
            out("test-gate %s → %s" % (VERSION, repo))
            for row in init_repo(repo, args.strict):
                out("  " + row)
            out("Далі: закоміть ці файли першим комітом; колеги після клонування — install-hook.")
            return 0
        if args.cmd == "install-hook":
            out("Хук встановлено: %s" % install_hook(repo))
            return 0
        if args.cmd == "uninstall-hook":
            path = uninstall_hook(repo)
            out("Хук прибрано: %s" % path if path else "Нашого хука тут нема.")
            return 0
        if args.cmd == "hook":
            return run_hook(repo, args.remote, sys.stdin.read() if stdin_text is None else stdin_text)
    except GateError as e:
        sys.stderr.write("test-gate: %s\n" % e)
        return 2
    return 2


if __name__ == "__main__":
    sys.exit(main())
