# -*- coding: utf-8 -*-
"""Тести test-gate: кожен створює справжній git-репозиторій, робить зміну й перевіряє рішення перевірки.

Запуск однаковий на ноутбуці, сервері й у CI: python -m unittest discover -s <тека цього файлу>
Скрипт шукаємо поруч (tools/test-gate.py) або в репозиторії проєкту (bin/test-gate.py).
"""
import importlib.util
import io
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from unittest import mock
from pathlib import Path

HERE = Path(__file__).resolve()
GATE = next(p for p in (HERE.parents[1] / "test-gate.py", HERE.parents[2] / "bin" / "test-gate.py") if p.is_file())
spec = importlib.util.spec_from_file_location("test_gate", GATE)
tg = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tg)

PHP_ACTION = """<?php
final class LeadTest extends WebTestCase
{
    public function testLeadIsSaved(): void
    {
        $client = static::createClient();
        $client->request('POST', '/request', ['name' => 'Іван { }']);
        self::assertSame(1, $this->leads()->count([]));
    }
}
"""
PHP_SMOKE = """<?php
final class PageTest extends WebTestCase
{
    public function testPageOpens(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1');
    }
}
"""
PHP_SMOKE_PLUS = PHP_SMOKE.replace("}\n}\n", "}\n\n    public function testAboutOpens(): void\n    {\n"
                                   "        static::createClient()->request('GET', '/about');\n"
                                   "        self::assertResponseStatusCodeSame(200);\n    }\n}\n")


class RepoCase(unittest.TestCase):
    def setUp(self):
        self.dir = Path(tempfile.mkdtemp(prefix="tg-"))
        self.addCleanup(shutil.rmtree, self.dir, True)
        env = mock.patch.dict(os.environ)  # тести не залежать від того, чи їх запущено в GitHub Actions
        env.start()
        self.addCleanup(env.stop)
        for key in ("GITHUB_ACTIONS", "GITHUB_STEP_SUMMARY", "TESTGATE_VIA"):
            os.environ.pop(key, None)
        self.git("init", "-q", "-b", "main")
        self.git("config", "user.email", "t@example.test")
        self.git("config", "user.name", "t")
        self.git("config", "core.autocrlf", "false")
        self.write("src/Lead.php", "<?php class Lead {}\n")
        self.write("tests/PageTest.php", PHP_SMOKE)
        self.commit("base")

    def git(self, *args):
        return subprocess.run(["git", *args], cwd=str(self.dir), check=True, capture_output=True, text=True,
                              encoding="utf-8").stdout

    def write(self, rel, text):
        path = self.dir / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8", newline="\n")

    def commit(self, message):
        self.git("add", "-A")
        self.git("commit", "-q", "-m", message)

    def branch(self, name):
        self.git("checkout", "-q", "-b", name)

    def codes(self, **kw):
        res = tg.check(self.dir, **kw)
        return [v["code"] for v in res["violations"]], res

    def cli(self, *argv, stdin_text=None):
        buf = io.StringIO()
        with redirect_stdout(buf):
            code = tg.main(["-C", str(self.dir), *argv], stdin_text=stdin_text)
        return code, buf.getvalue()


class CheckRules(RepoCase):
    def test_code_without_tests_is_violation(self):
        self.branch("feat/lead")
        self.write("src/Lead.php", "<?php class Lead { public $name; }\n")
        self.commit("lead name")
        codes, res = self.codes()
        self.assertEqual(["no-tests"], codes)
        self.assertFalse(res["ok"])

    def test_code_with_action_test_passes(self):
        self.branch("feat/lead")
        self.write("src/Lead.php", "<?php class Lead { public $name; }\n")
        self.write("tests/LeadTest.php", PHP_ACTION)
        self.commit("lead with test")
        codes, res = self.codes()
        self.assertEqual([], codes)
        self.assertEqual(1, res["tests_new"])
        self.assertEqual([], res["warnings"])

    def test_only_smoke_test_is_violation_and_named_in_warnings(self):
        self.branch("feat/about")
        self.write("src/Lead.php", "<?php class Lead { public $about; }\n")
        self.write("tests/PageTest.php", PHP_SMOKE_PLUS)
        self.commit("about page")
        codes, res = self.codes()
        self.assertEqual(["no-action-test"], codes)
        self.assertEqual(1, res["tests_touched"], "стара функція в тому ж файлі не рахується")
        self.assertIn("testAboutOpens", res["warnings"][0])

    def test_fix_branch_needs_new_test(self):
        self.write("tests/LeadTest.php", PHP_ACTION)
        self.commit("lead test on main")
        self.branch("fix/lead-count")
        self.write("src/Lead.php", "<?php class Lead { public $fixed; }\n")
        self.write("tests/LeadTest.php", PHP_ACTION.replace("assertSame(1,", "assertSame(2,"))
        self.commit("change expectation only")
        codes, _ = self.codes()
        self.assertEqual(["fix-without-test"], codes)

        self.write("tests/LeadTest.php", PHP_ACTION.replace("}\n}\n", "}\n\n    public function testBugRepro(): void\n"
                   "    {\n        static::createClient()->request('POST', '/request', []);\n"
                   "        self::assertSame(0, $this->leads()->count([]));\n    }\n}\n"))
        self.commit("add repro test")
        codes, res = self.codes()
        self.assertEqual([], codes)
        self.assertEqual(1, res["tests_new"])

    def test_waiver_in_commit_message_lets_through(self):
        self.branch("feat/rename")
        self.write("src/Lead.php", "<?php class Lead { /* renamed */ }\n")
        self.commit("rename\n\nTests: none — лише перейменування, поведінка та сама")
        codes, res = self.codes()
        self.assertEqual(["no-tests"], codes)
        self.assertTrue(res["ok"])
        self.assertIn("перейменування", res["waiver"])

    def test_templates_only_is_warning_not_violation(self):
        self.branch("feat/footer")
        self.write("templates/footer.html.twig", "<footer>x</footer>\n")
        self.commit("footer")
        codes, res = self.codes()
        self.assertEqual([], codes)
        self.assertEqual(1, len(res["warnings"]))

    def test_uncommitted_work_is_checked_too(self):
        self.branch("feat/wip")
        self.write("src/New.php", "<?php class NewThing {}\n")
        codes, _ = self.codes()
        self.assertEqual(["no-tests"], codes)
        self.write("tests/NewTest.php", PHP_ACTION)
        codes, res = self.codes()
        self.assertEqual([], codes)
        self.assertEqual(1, res["tests_new"])

    def test_cli_exit_codes(self):
        self.branch("feat/lead")
        self.write("src/Lead.php", "<?php class Lead { public $name; }\n")
        self.commit("lead name")
        code, text = self.cli("check")
        self.assertEqual(1, code)
        self.assertIn("[no-tests]", text)
        self.write("tests/LeadTest.php", PHP_ACTION)
        self.commit("test")
        code, text = self.cli("check")
        self.assertEqual(0, code)
        self.assertIn("Гаразд", text)


class InitNewProject(RepoCase):
    def test_init_puts_working_gate_into_repo(self):
        self.write("composer.json", "{}\n")
        self.commit("composer")
        code, text = self.cli("init")
        self.assertEqual(0, code, text)

        copy = self.dir / "bin" / "test-gate.py"
        self.assertEqual(GATE.read_bytes(), copy.read_bytes())
        self.assertTrue((self.dir / "tests" / "tools" / "test_test_gate.py").is_file())
        cfg = json.loads((self.dir / "testgate.json").read_text(encoding="utf-8"))
        self.assertEqual("vendor/bin/phpunit", cfg["run"]["all"])
        workflow = (self.dir / ".github" / "workflows" / "test-gate.yml").read_text(encoding="utf-8")
        self.assertIn("bin/test-gate.py check --advisory --base", workflow)
        self.assertIn(tg.MARK, tg.hook_path(self.dir).read_text(encoding="utf-8"))

        run = subprocess.run([sys.executable, str(copy), "-C", str(self.dir), "check"], capture_output=True)
        self.assertEqual(0, run.returncode, "копія в репо працює сама, і гілка з нею проходить перевірку")

        (self.dir / "testgate.json").write_text('{"run": {"all": "make test"}}', encoding="utf-8")
        self.assertEqual(0, self.cli("init")[0])
        self.assertEqual('{"run": {"all": "make test"}}', (self.dir / "testgate.json").read_text(encoding="utf-8"),
                         "повторний init не затирає свій конфіг проєкту")

    def test_init_strict_and_existing_ci_job(self):
        self.write(".github/workflows/ci.yml", "jobs:\n  gate:\n    steps:\n      - run: python3 bin/test-gate.py check\n")
        self.assertEqual(0, self.cli("init")[0])
        self.assertFalse((self.dir / ".github" / "workflows" / "test-gate.yml").exists(), "job уже є в ci.yml")

        (self.dir / ".github" / "workflows" / "ci.yml").unlink()
        self.assertEqual(0, self.cli("init", "--strict")[0])
        workflow = (self.dir / ".github" / "workflows" / "test-gate.yml").read_text(encoding="utf-8")
        self.assertIn("bin/test-gate.py check --base", workflow)
        self.assertNotIn("check --advisory", workflow, "у суворому режимі команда без прапорця")


class AdvisoryMode(RepoCase):
    def setUp(self):
        super().setUp()
        self.branch("feat/lead")
        self.write("src/Lead.php", "<?php class Lead { public $name; }\n")
        self.commit("lead name")

    def test_advisory_reports_violation_but_does_not_fail(self):
        code, text = self.cli("check", "--advisory")
        self.assertEqual(0, code)
        self.assertIn("[no-tests]", text)
        self.assertIn("Режим попередження", text)
        self.assertEqual(1, self.cli("check")[0], "без прапорця те саме порушення блокує")

    def test_github_annotations_and_summary(self):
        summary = self.dir.parent / (self.dir.name + "-summary.md")
        self.addCleanup(lambda: summary.unlink() if summary.exists() else None)
        env = {"GITHUB_ACTIONS": "true", "GITHUB_STEP_SUMMARY": str(summary)}
        with mock.patch.dict(os.environ, env):
            code, text = self.cli("check", "--advisory")
            self.assertEqual(0, code)
            self.assertIn("::warning title=test-gate no-tests::", text)
            self.assertNotIn("::error", text)
            self.assertIn("no-tests", summary.read_text(encoding="utf-8"))
            self.assertIn("Tests: none", summary.read_text(encoding="utf-8"))

            code, text = self.cli("check")
            self.assertEqual(1, code)
            self.assertIn("::error title=test-gate no-tests::", text)

        code, text = self.cli("check", "--advisory")
        self.assertNotIn("::warning", text, "поза GitHub Actions анотацій нема")


class Classifier(unittest.TestCase):
    def verdicts(self, name, src):
        funcs, lang = tg.functions_of(name, src)
        return {f[0]: tg.verdict_of(f[3], lang) for f in funcs}

    def test_php_post_with_status_only_is_weak(self):
        src = PHP_ACTION.replace("self::assertSame(1, $this->leads()->count([]));", "self::assertResponseIsSuccessful();")
        self.assertEqual({"testLeadIsSaved": "weak"}, self.verdicts("LeadTest.php", src))

    def test_php_attribute_test_and_braces_in_strings(self):
        src = "<?php class T {\n    #[Test]\n    public function leadIsSaved(): void {\n        $s = '}{';\n" \
              "        $this->bus->dispatch(new Save());\n        self::assertCount(1, $this->sent);\n    }\n" \
              "    private function helper(): void { self::assertTrue(true); }\n}\n"
        self.assertEqual({"leadIsSaved": "ok"}, self.verdicts("T.php", src))

    def test_python_playwright(self):
        src = (
            "def test_home_opens(page):\n    page.goto('/')\n    expect(page.locator('h1')).to_be_visible()\n\n"
            "def test_order(\n    page,\n    mailbox,\n):\n    page.goto('/cart/')\n    page.fill('#phone', '1')\n"
            "    page.click('text=Оформити')\n    assert mailbox.count() == 2\n\n"
            "def test_nothing(page):\n    page.goto('/')\n"
        )
        self.assertEqual({"test_home_opens": "smoke", "test_order": "ok", "test_nothing": "noassert"},
                         self.verdicts("test_shop.py", src))

    def test_python_unittest_style_asserts(self):
        src = (
            "class T(TestCase):\n    def test_saved(self):\n        self.client.post('/lead', {})\n"
            "        self.assertEqual(1, Lead.objects.count())\n\n"
            "    def test_opens(self):\n        r = self.client.get('/')\n        self.assertEqual(200, r.status_code)\n\n"
            "    def test_posted_status_only(self):\n        r = self.client.post('/lead', {})\n"
            "        self.assertEqual(r.status_code, 200)\n"
        )
        self.assertEqual({"test_saved": "ok", "test_opens": "smoke", "test_posted_status_only": "weak"},
                         self.verdicts("test_views.py", src))

    def test_js_playwright(self):
        src = (
            "test('home opens', async ({ page }) => {\n  await page.goto('/');\n"
            "  await expect(page).toHaveTitle(/Shop/);\n});\n"
            "test('adds to cart', async ({ page }) => {\n  await page.goto('/p/1');\n  await page.click('#buy');\n"
            "  await expect(page.locator('.cart-count')).toHaveText('1');\n});\n"
        )
        self.assertEqual({"home opens": "smoke", "adds to cart": "ok"}, self.verdicts("shop.spec.ts", src))


class HookAndRun(RepoCase):
    def test_hook_blocks_feature_push_to_origin_only(self):
        self.branch("feat/lead")
        self.write("src/Lead.php", "<?php class Lead { public $name; }\n")
        self.commit("lead name")
        sha = self.git("rev-parse", "HEAD").strip()
        main_sha = self.git("rev-parse", "main").strip()
        zero = "0" * 40
        line = "refs/heads/feat/lead %s refs/heads/feat/lead %s\n" % (sha, zero)

        code, text = self.cli("hook", "origin", "url", stdin_text=line)
        self.assertEqual(1, code)
        self.assertIn("пуш зупинено", text)
        self.assertEqual(0, self.cli("hook", "prod", "url", stdin_text=line)[0], "інші remote не перевіряємо")
        self.assertEqual(0, self.cli("hook", "origin", "url",
                                     stdin_text="refs/heads/main %s refs/heads/main %s\n" % (main_sha, zero))[0])
        self.assertEqual(0, self.cli("hook", "origin", "url",
                                     stdin_text="(delete) %s refs/heads/feat/lead %s\n" % (zero, sha))[0])

    def test_install_hook_runs_on_real_push_and_respects_foreign_hook(self):
        origin = Path(tempfile.mkdtemp(prefix="tg-origin-"))
        self.addCleanup(shutil.rmtree, origin, True)
        subprocess.run(["git", "init", "-q", "--bare", "-b", "main", str(origin)], check=True)
        self.git("remote", "add", "origin", str(origin))
        self.git("push", "-q", "origin", "main")
        self.assertEqual(0, self.cli("install-hook")[0])
        hook = tg.hook_path(self.dir)
        self.assertIn(tg.MARK, hook.read_text(encoding="utf-8"))

        self.branch("feat/lead")
        self.write("src/Lead.php", "<?php class Lead { public $name; }\n")
        self.commit("lead name")
        env = dict(os.environ, PATH=os.pathsep.join([str(Path(sys.executable).parent), os.environ["PATH"]]))
        push = subprocess.run(["git", "push", "origin", "feat/lead"], cwd=str(self.dir), capture_output=True,
                              text=True, encoding="utf-8", errors="replace", env=env)
        self.assertNotEqual(0, push.returncode, push.stdout + push.stderr)
        self.assertIn("no-tests", push.stdout + push.stderr)
        self.assertEqual("", self.git("ls-remote", "origin", "feat/lead"), "гілка не мала потрапити в origin")

        self.write("tests/LeadTest.php", PHP_ACTION)
        self.commit("test")
        push = subprocess.run(["git", "push", "origin", "feat/lead"], cwd=str(self.dir), capture_output=True,
                              text=True, encoding="utf-8", errors="replace", env=env)
        self.assertEqual(0, push.returncode, push.stdout + push.stderr)
        self.assertIn("feat/lead", self.git("ls-remote", "origin", "feat/lead"))

        self.assertEqual(0, self.cli("uninstall-hook")[0])
        self.assertFalse(hook.exists())
        hook.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8", newline="\n")
        self.assertEqual(2, self.cli("install-hook")[0])
        self.assertEqual("#!/bin/sh\nexit 0\n", hook.read_text(encoding="utf-8"))

    def test_run_composes_same_command_through_wrapper(self):
        self.write("testgate.json", '{"run": {"unit": "vendor/bin/phpunit --testsuite unit",'
                   ' "e2e": {"cmd": "python -m pytest tests/e2e", "exec": false}},'
                   ' "exec": {"stand": "docker compose exec -T php", "wt": "bash wt.sh exec {wt}",'
                   ' "ssh": "ssh dev \\"wt.sh exec {wt} {cmd}\\""}}')
        self.assertEqual("vendor/bin/phpunit --testsuite unit", tg.run_command(self.dir, "unit"))
        self.assertEqual("docker compose exec -T php vendor/bin/phpunit --testsuite unit",
                         tg.run_command(self.dir, "unit", via="stand"))
        self.assertEqual("bash wt.sh exec e2e vendor/bin/phpunit --testsuite unit",
                         tg.run_command(self.dir, "unit", via="wt", wt="e2e"))
        self.assertEqual('ssh dev "wt.sh exec e2e vendor/bin/phpunit --testsuite unit"',
                         tg.run_command(self.dir, "unit", via="ssh", wt="e2e"))
        self.assertEqual("python -m pytest tests/e2e", tg.run_command(self.dir, "e2e", via="stand"))
        self.write("testgate.json", '{"run": {"gate": {"cmd": "{python} -m unittest", "exec": false}}}')
        py = '"%s"' % sys.executable if " " in sys.executable else sys.executable
        self.assertEqual(py + " -m unittest", tg.run_command(self.dir, "gate"),
                         "{python} — той самий інтерпретатор, яким запущено test-gate (python чи python3)")
        code, text = self.cli("run", "missing", "--print")
        self.assertEqual(2, code)


if __name__ == "__main__":
    unittest.main()
