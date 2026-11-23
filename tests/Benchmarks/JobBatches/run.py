"""Run one engine at a time on private Docker volumes. Print one JSON record per run."""
import argparse
import json
import os
import platform
import secrets
import signal
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
PHP = ["php", str(Path(__file__).with_name("benchmark.php"))]
IMAGES = {"mysql": "mysql:9", "mariadb": "mariadb:10", "pgsql": "pgvector/pgvector:0.8.7-pg17"}


def output(*args, **kwargs):
    return subprocess.check_output(args, text=True, timeout=60, **kwargs).strip()


def sample(args, env):
    process = subprocess.Popen(PHP + args, cwd=ROOT, env=env, stdout=subprocess.PIPE, text=True, start_new_session=True)
    try:
        stdout, _ = process.communicate(timeout=300)
    except BaseException:
        os.killpg(process.pid, signal.SIGKILL)
        process.wait()
        raise
    if process.returncode:
        raise RuntimeError(f"{args} exited with {process.returncode}")
    return json.loads(stdout) if stdout.strip() else None


def emit(record):
    print(json.dumps(record, separators=(",", ":")), flush=True)


def campaign(engine, env, repeats, phase):
    def check(variant):
        sample(["check", engine, variant], env)
    final_variant = "C-cte" if engine == "pgsql" else "C-noindex"
    candidate = final_variant if phase == "final" else "C"
    variants = ["A", "B", candidate]
    extra_checks = [] if phase == "final" else (["C-reuse"] if engine != "pgsql" else ["C-reuse", "C-cte", "C-reuse-cte"])
    for variant in variants + extra_checks:
        check(variant)
    for variant in ([candidate] if phase == "final" else (["C", "C-cte"] if engine == "pgsql" else ["C"])):
        subprocess.run(["php", str(Path(__file__).with_name("interleavings.php")), "verify", engine, variant],
                       cwd=ROOT, env=env, timeout=60, check=True)
    emit({"engine": engine, "correctness": "pass", "overlapping_deliveries": "pass",
          **sample(["settings", engine, candidate], env)})
    scenarios = []
    if phase in ("final", "core"):
        scenarios += [(case, variants, 1000, 50) for case in ["clean", "heavy", "independent"]]
    if phase == "core":
        scenarios += [("progress", ["B", "C"], 1000, 50), ("readids", ["B", "C"], 100, 10)]
    if phase == "savings":
        savings = ["C", "C-reuse"]
        if engine == "pgsql":
            savings += ["C-cte", "C-reuse-cte"]
        scenarios += [(case, savings, 1000, 50) for case in ["clean", "independent"]]
    if phase == "latency":
        scenarios += [(case, ["C", "C-cte"], 1000, 50) for case in ["clean", "independent"]]
    if phase == "index":
        # Isolate the secondary index from the parent schema and changing run conditions.
        scenarios += [("independent", ["B", "C", "C-noindex"], 5000, 50)]
    for iteration in range(repeats):
        for case, choices, jobs, workers in scenarios:
            offset = iteration % len(choices)
            for variant in choices[offset:] + choices[:offset]:
                result = sample(["sample", engine, variant, case, str(jobs), str(workers)], env)
                emit({**result, "iteration": iteration + 1, "phase": phase})


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--engine", choices=[*IMAGES, "all"], default="all")
    parser.add_argument("--phase", choices=["core", "savings", "index", "latency", "sparse", "final"], default="core")
    parser.add_argument("--repeats", type=int, default=5)
    args = parser.parse_args()
    emit({"php": output("php", "-r", "echo PHP_VERSION;"), "kernel": platform.release(),
          "cpus": len(os.sched_getaffinity(0)), "components": output("git", "-C", str(ROOT), "rev-parse", "HEAD")})
    with tempfile.TemporaryDirectory(prefix="batch-verify-") as temp:
        for variant in ["A", "B", "C", "C-reuse"]:
            sample(["check", "sqlite", variant], dict(os.environ, BENCH_DSN=f"sqlite:{temp}/check.sqlite"))
    for engine in IMAGES if args.engine == "all" else [args.engine]:
        pg = engine == "pgsql"
        password = secrets.token_urlsafe(25)
        port = 5432 if pg else 3306
        env_key = "POSTGRES_PASSWORD" if pg else "MYSQL_ROOT_PASSWORD"
        container_env = dict(os.environ, **{env_key: password})
        image = IMAGES[engine]
        container = output("docker", "run", "-d", "--pull=never", "-p", f"127.0.0.1::{port}",
                           "-e", env_key, "-e", ("POSTGRES_DB" if pg else "MYSQL_DATABASE")+"=batchbench", image, env=container_env)
        try:
            mapped = output("docker", "port", container, f"{port}/tcp").split(":")[-1]
            driver = "pgsql" if pg else "mysql"
            env = dict(os.environ, BENCH_DSN=f"{driver}:host=127.0.0.1;port={mapped};dbname=batchbench",
                       BENCH_USER="postgres" if pg else "root", BENCH_PASSWORD=password)
            deadline = time.monotonic() + 100
            while subprocess.run(["php", "-r", 'try {new PDO(getenv("BENCH_DSN"),getenv("BENCH_USER"),getenv("BENCH_PASSWORD"));} catch(Throwable){exit(1);}'],
                                 env=env, timeout=8).returncode:
                if time.monotonic() > deadline:
                    raise TimeoutError("private server startup")
                time.sleep(1)
            emit({"engine": engine, "image": output("docker", "inspect", "--format", "{{.Image}}", container)})
            if args.phase == "sparse":
                if engine not in ("mysql", "mariadb"):
                    raise ValueError("MySQL-family sparse-index control")
                result = output("php", str(Path(__file__).with_name("sparse.php")), "verify", engine, "C", cwd=ROOT, env=env)
                emit(json.loads(result))
            elif args.phase == "latency":
                if engine != "pgsql":
                    raise ValueError("PostgreSQL-only control")
                proxy = subprocess.Popen([sys.executable, str(Path(__file__).with_name("latency.py")), mapped],
                                         stdout=subprocess.PIPE, text=True)
                try:
                    proxyport = proxy.stdout.readline().strip()
                    routed = dict(env, BENCH_DSN=env["BENCH_DSN"].replace(f"port={mapped};", f"port={proxyport};"))
                    probe = '$p=new PDO(getenv("BENCH_DSN"),getenv("BENCH_USER"),getenv("BENCH_PASSWORD"));for($i=0;$i<31;$i++){$t=hrtime(true);$p->query("select 1")->fetchColumn();$a[]=(hrtime(true)-$t)/1e6;}sort($a);echo $a[15];'
                    emit({"engine":engine, "select_1_direct_ms":float(output("php", "-r", probe, env=env)),
                          "select_1_proxy_ms":float(output("php", "-r", probe, env=routed))})
                    campaign(engine, routed, args.repeats, args.phase)
                finally:
                    proxy.terminate()
                    proxy.wait(timeout=10)
            else:
                campaign(engine, env, args.repeats, args.phase)
        finally:
            output("docker", "rm", "-fv", container)


if __name__ == "__main__":
    main()
