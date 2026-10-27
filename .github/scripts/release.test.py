import os
from pathlib import Path
import re
import shlex
import subprocess
import tempfile
import unittest


REPOSITORY = Path(__file__).resolve().parents[2]


class ReleaseTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix="hypervel-release-test-")
        self.addCleanup(temporary.cleanup)
        self.directory = Path(temporary.name)
        self.source = self.directory / "source"
        self.source.mkdir()
        self.branch = re.search(
            r'^RELEASE_BRANCH="([^"]+)"',
            (REPOSITORY / "bin/release.sh").read_text(),
            re.MULTILINE,
        ).group(1)
        self.version = f"v{self.branch}.0-test"
        configuration = self.directory / "gitconfig"
        configuration.write_text(
            '[user]\n name = Release Test\n email = release@example.test\n'
            f'[url "{self.directory.as_uri()}/"]\n'
            ' insteadOf = git@github.com:hypervel/\n'
        )
        self.environment = {
            **os.environ,
            "GIT_CONFIG_GLOBAL": str(configuration),
            "GIT_CONFIG_NOSYSTEM": "1",
            "GIT_ALLOW_PROTOCOL": "file",
            "GIT_TERMINAL_PROMPT": "0",
        }
        self.run_command("git", "init", "-b", self.branch)
        (self.source / "bin").mkdir()
        for name in ("split.sh", "release.sh", "splitsh-lite"):
            self.run_command("cp", str(REPOSITORY / "bin" / name), str(self.source / "bin" / name))
        for package in ("alpha", "beta"):
            (self.source / "src" / package).mkdir(parents=True)
            self.run_command("git", "init", "--bare", str(self.remote(package)))
        self.run_command("git", "init", "--bare", str(self.remote("components")))
        self.run_command("git", "remote", "add", "origin", str(self.remote("components")))
        self.commit("initial")

    def run_command(self, *arguments, check=True):
        result = subprocess.run(
            arguments, cwd=self.source, env=self.environment,
            text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=30,
        )
        if check and result.returncode:
            self.fail(f"{arguments!r} failed:\n{result.stdout}")
        return result

    def remote(self, package):
        return self.directory / f"{package}.git"

    def remote_git(self, package, *arguments, check=True):
        return self.run_command("git", f"--git-dir={self.remote(package)}", *arguments, check=check)

    def commit(self, contents, publish=True):
        for package in ("alpha", "beta"):
            (self.source / "src" / package / "contents").write_text(contents)
        self.run_command("git", "add", ".")
        self.run_command("git", "commit", "-m", contents)
        if publish:
            self.run_command("git", "push", "origin", self.branch)
        return self.run_command("git", "rev-parse", "HEAD").stdout.strip()

    def release(self, *options, check=True):
        return self.run_command("bash", "bin/release.sh", self.version, *options, check=check)

    def assert_release(self, commit, contents):
        self.assertEqual(
            self.remote_git("components", "rev-parse", f"refs/tags/{self.version}").stdout.strip(),
            commit,
        )
        for package in ("alpha", "beta"):
            self.assertEqual(
                self.remote_git(package, "show", f"refs/tags/{self.version}:contents").stdout,
                contents,
            )

    def assert_no_tag(self, package):
        self.assertNotEqual(
            self.remote_git(package, "rev-parse", "--verify", f"refs/tags/{self.version}", check=False).returncode,
            0,
        )

    def hook(self, package, name, script):
        path = self.remote(package) / "hooks" / name
        path.write_text("#!/bin/sh\nset -e\n" + script)
        path.chmod(0o755)

    def test_check_accepts_empty_repositories_without_changing_any_refs(self):
        commit = self.run_command("git", "rev-parse", "HEAD").stdout
        result = self.run_command("bash", "bin/split.sh", "--check")
        self.assertIn("All 2 split repositories are accessible.", result.stdout)
        self.assertEqual(self.run_command("git", "rev-parse", "HEAD").stdout, commit)
        self.assertEqual(self.run_command("git", "for-each-ref", "refs/hypervel-split/").stdout, "")
        self.assertFalse((self.source / ".git" / "splitsh.db").exists())
        for package in ("alpha", "beta"):
            self.assertEqual(self.remote_git(package, "for-each-ref").stdout, "")

    def test_check_reports_all_missing_repositories_together(self):
        for package in ("alpha", "beta"):
            self.run_command("mv", str(self.remote(package)), str(self.directory / f"{package}-unavailable.git"))
        result = self.run_command("bash", "bin/split.sh", "--check", check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Missing or inaccessible split repositories:", result.stdout.splitlines())
        self.assertIn("  hypervel/alpha\n  hypervel/beta\n", result.stdout)

    def test_missing_destination_stops_split_and_release_before_any_push(self):
        self.run_command("mv", str(self.remote("beta")), str(self.directory / "beta-unavailable.git"))
        for script, arguments in (("split.sh", []), ("release.sh", [self.version])):
            with self.subTest(script=script):
                result = self.run_command("bash", f"bin/{script}", *arguments, check=False)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("Missing or inaccessible split repositories:", result.stdout.splitlines())
                self.assertIn("  hypervel/beta", result.stdout.splitlines())
                self.assertEqual(self.remote_git("alpha", "for-each-ref").stdout, "")
                self.assert_no_tag("components")

    def test_selected_package_checks_only_its_destination(self):
        self.run_command("mv", str(self.remote("beta")), str(self.directory / "beta-unavailable.git"))
        result = self.run_command("bash", "bin/split.sh", "alpha", "--check")
        self.assertIn("All 1 split repositories are accessible.", result.stdout)
        self.run_command("bash", "bin/split.sh", "alpha")
        self.assertEqual(self.remote_git("alpha", "show", f"refs/heads/{self.branch}:contents").stdout, "initial")

    def test_check_uses_the_selected_source_commit_package_list(self):
        original = self.run_command("git", "rev-parse", "HEAD").stdout.strip()
        (self.source / "src" / "gamma").mkdir()
        (self.source / "src" / "gamma" / "contents").write_text("new package")
        self.commit("later")
        result = self.run_command("bash", "bin/split.sh", "--check", check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Missing or inaccessible split repositories:", result.stdout.splitlines())
        self.assertIn("  hypervel/gamma", result.stdout.splitlines())
        self.run_command("bash", "bin/split.sh", "--ref", original, "--check")
        self.run_command("bash", "bin/split.sh", "--ref", original)
        for package in ("alpha", "beta"):
            self.assertEqual(self.remote_git(package, "show", f"refs/heads/{self.branch}:contents").stdout, "initial")

    def test_release_refreshes_stale_splits_and_repeated_splits_are_unchanged(self):
        self.run_command("bash", "bin/split.sh")
        commit = self.commit("release")
        self.run_command("git", "tag", "unrelated-local-tag")
        self.release()
        self.assert_release(commit, "release")
        self.assertNotEqual(
            self.remote_git("components", "rev-parse", "--verify", "refs/tags/unrelated-local-tag", check=False).returncode,
            0,
        )
        self.run_command("bash", "bin/split.sh")
        for package in ("alpha", "beta"):
            self.assertEqual(
                self.remote_git(package, "rev-parse", f"refs/heads/{self.branch}").stdout,
                self.remote_git(package, "rev-parse", f"refs/tags/{self.version}").stdout,
            )

    def test_source_push_during_splitting_does_not_change_the_release(self):
        commit = self.commit("release")
        later = self.commit("later")
        self.run_command("git", "reset", "--hard", commit)
        self.remote_git("components", "update-ref", f"refs/heads/{self.branch}", commit)
        self.hook("alpha", "post-receive", (
            f"git --git-dir={shlex.quote(str(self.remote('components')))} "
            f"update-ref refs/heads/{self.branch} {later}\n"
        ))
        self.release()
        self.assert_release(commit, "release")
        self.assertEqual(
            self.remote_git("components", "rev-parse", f"refs/heads/{self.branch}").stdout.strip(), later,
        )

    def test_source_push_before_fetch_does_not_change_the_release(self):
        commit = self.commit("release")
        self.commit("later")
        self.run_command("git", "reset", "--hard", commit)
        self.release()
        self.assert_release(commit, "release")

    def test_tags_use_recorded_splits_when_a_destination_branch_moves(self):
        self.run_command("bash", "bin/split.sh")
        previous = self.remote_git("alpha", "rev-parse", f"refs/heads/{self.branch}").stdout.strip()
        commit = self.commit("release")
        self.hook("beta", "post-receive", (
            "while read previous current reference; do\n"
            f' if [ "$reference" = "refs/heads/{self.branch}" ]; then\n'
            f"  git --git-dir={shlex.quote(str(self.remote('alpha')))} "
            f"update-ref refs/heads/{self.branch} {previous}\n"
            " fi\ndone\n"
        ))
        self.release()
        self.assert_release(commit, "release")
        self.assertEqual(
            self.remote_git("alpha", "rev-parse", f"refs/heads/{self.branch}").stdout.strip(), previous,
        )

    def test_failed_split_does_not_publish_any_release_tags(self):
        self.hook("beta", "pre-receive", "echo 'Rejecting beta split' >&2\nexit 1\n")
        result = self.release(check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Rejecting beta split", result.stdout)
        for package in ("components", "alpha", "beta"):
            self.assert_no_tag(package)
        self.assertEqual(self.run_command("git", "for-each-ref", "refs/hypervel-split/").stdout, "")

    def test_failed_component_tag_does_not_publish_the_framework_tag(self):
        self.hook("beta", "pre-receive", (
            "while read previous current reference; do\n"
            ' case "$reference" in refs/tags/*) echo "Rejecting beta tag" >&2; exit 1;; esac\n'
            "done\n"
        ))
        result = self.release(check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Rejecting beta tag", result.stdout)
        self.assert_no_tag("components")
        self.assert_no_tag("beta")

    def test_existing_tags_require_explicit_force_to_move(self):
        original = self.run_command("git", "rev-parse", "HEAD").stdout.strip()
        self.release()
        replacement = self.commit("replacement")
        self.assertNotEqual(self.release(check=False).returncode, 0)
        self.assert_release(original, "initial")
        self.release("-f")
        self.assert_release(replacement, "replacement")

    def test_selected_package_split_and_explicit_source_commit(self):
        original = self.run_command("git", "rev-parse", "HEAD").stdout.strip()
        self.commit("later")
        self.run_command("bash", "bin/split.sh", "alpha", "--ref", original)
        self.assertEqual(
            self.remote_git("alpha", "show", f"refs/heads/{self.branch}:contents").stdout, "initial",
        )
        self.assertNotEqual(
            self.remote_git("beta", "rev-parse", "--verify", f"refs/heads/{self.branch}", check=False).returncode, 0,
        )

    def test_unpublished_commit_is_rejected_before_splitting(self):
        self.commit("unpublished", publish=False)
        self.assertNotEqual(self.release(check=False).returncode, 0)
        self.assert_no_tag("components")
        for package in ("alpha", "beta"):
            self.assertEqual(self.remote_git(package, "for-each-ref").stdout, "")

    def test_removed_package_cannot_publish_its_old_history(self):
        self.run_command("bash", "bin/split.sh")
        previous = self.remote_git("alpha", "rev-parse", f"refs/heads/{self.branch}").stdout
        self.run_command("git", "rm", "-r", "src/alpha")
        self.run_command("git", "commit", "-m", "Remove alpha")
        self.assertNotEqual(self.run_command("bash", "bin/split.sh", "alpha", check=False).returncode, 0)
        self.assertEqual(self.remote_git("alpha", "rev-parse", f"refs/heads/{self.branch}").stdout, previous)
        self.assertEqual(self.run_command("git", "for-each-ref", "refs/hypervel-split/").stdout, "")


if __name__ == "__main__":
    unittest.main()
