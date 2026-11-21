"""Compare the production SQL repository with the Redis results prototype on isolated servers."""
import argparse
import contextlib
import json
import os
import platform
import secrets
import signal
import subprocess
import time
from pathlib import Path

HERE = Path(__file__).resolve().parent
IMAGES = {"pgsql": "pgvector/pgvector:0.8.7-pg17", "mysql": "mysql:9", "mariadb": "mariadb:10"}
WORKLOADS = {"clean": (1000, 50), "heavy": (1000, 50), "independent": (1000, 50),
             "progress": (1000, 50), "singleton": (1000, 50), "single_worker": (1000, 1)}


def output(*command, env=None):
    return subprocess.check_output(command, text=True, timeout=90, env=env).strip()


@contextlib.contextmanager
def server(image, port, args=(), settings=(), env=None):
    container = output("docker", "run", "--pull=never", "-d", "-p", f"127.0.0.1::{port}",
                       *settings, image, *args, env=env)
    try:
        yield container, output("docker", "port", container, f"{port}/tcp").split(":")[-1]
    finally:
        subprocess.run(["docker", "rm", "-fv", container], check=True, stdout=subprocess.DEVNULL, timeout=60)


def sample(engine, variant, case, jobs, workers, env):
    command = ["php", str(HERE / "benchmark.php"), engine, variant, case, str(jobs), str(workers)]
    with subprocess.Popen(command, stdout=subprocess.PIPE, text=True, env=env, start_new_session=True) as process:
        try:
            stdout, _ = process.communicate(timeout=90)
        except BaseException:
            os.killpg(process.pid, signal.SIGKILL)
            raise
        if process.returncode:
            raise subprocess.CalledProcessError(process.returncode, command)
    return json.loads(stdout)


def emit(**entry):
    print(json.dumps(entry, separators=(",", ":")), flush=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--lazy", action="store_true", help="PostgreSQL eager vs lazy counter hydration control")
    lazy = parser.parse_args().lazy
    engines = {"pgsql": IMAGES["pgsql"]} if lazy else IMAGES
    workloads = {key: WORKLOADS[key] for key in ("clean", "independent", "singleton")} if lazy else WORKLOADS
    emit(commit=output("git", "rev-parse", "HEAD"), cpus=len(os.sched_getaffinity(0)),
         kernel=platform.release(), php=output("php", "-r", "echo PHP_VERSION;"))
    for engine, image in engines.items():
        pg = engine == "pgsql"
        password = secrets.token_urlsafe(24)
        password_key = "POSTGRES_PASSWORD" if pg else "MYSQL_ROOT_PASSWORD"
        settings = ("-e", password_key, "-e", ("POSTGRES_DB" if pg else "MYSQL_DATABASE") + "=batchbench")
        with contextlib.ExitStack() as stack:
            sql, port = stack.enter_context(server(image, 5432 if pg else 3306, settings=settings,
                                                   env=dict(os.environ, **{password_key: password})))
            env = dict(os.environ, BENCH_DSN=f"{'pgsql' if pg else 'mysql'}:host=127.0.0.1;port={port};dbname=batchbench",
                       BENCH_USER="postgres" if pg else "root", BENCH_PASSWORD=password)
            probe = 'try{new PDO(getenv("BENCH_DSN"),getenv("BENCH_USER"),getenv("BENCH_PASSWORD"));}catch(Throwable){exit(1);}'
            until = time.monotonic() + 110
            while subprocess.run(["php", "-r", probe], env=env, timeout=6).returncode:
                if time.monotonic() >= until:
                    raise RuntimeError(f"{engine} startup failed")
                time.sleep(1)
            sql_settings = ("select name,setting from pg_settings where name in ('server_version','synchronous_commit','fsync','full_page_writes')"
                            if pg else "show variables where Variable_name in ('version','innodb_flush_log_at_trx_commit','sync_binlog','log_bin')")
            query = '$db=new PDO(getenv("BENCH_DSN"),getenv("BENCH_USER"),getenv("BENCH_PASSWORD"));echo json_encode($db->query($argv[1])->fetchAll(PDO::FETCH_KEY_PAIR));'
            emit(engine=engine, sql_settings=json.loads(output("php", "-r", query, sql_settings, env=env)),
                 image=output("docker", "inspect", "-f", "{{.Image}}", sql))
            ports = {}
            for mode in (("always",) if lazy else ("always", "everysec")):
                container, redis_port = stack.enter_context(server("redis:latest", 6379, args=(
                    "redis-server", "--save", "", "--appendonly", "yes", "--appendfsync", mode,
                    "--auto-aof-rewrite-percentage", "0", "--maxmemory-policy", "noeviction")))
                ports[mode] = redis_port
                config = output("docker", "exec", container, "redis-cli", "config", "get", "appendfsync", "appendonly","maxmemory-policy")
                emit(engine=engine, redis=mode, config=config.splitlines(),
                     version=output("docker", "exec", container, "redis-server", "--version"))
            if lazy:
                ports["lazy"] = ports["always"]
            else:
                ports["sql"] = ports["everysec"]
            if lazy:
                report = output("php", str(HERE / "checks.php"), env=dict(env, BENCH_REDIS_PORT=ports["always"]))
                emit(engine=engine, protocol=report)
                control = output("php", str(HERE / "enrollment.php"), env=dict(env, BENCH_REDIS_PORT=ports["always"]))
                emit(engine=engine, enrollment=[json.loads(line) for line in control.splitlines()])
            for variant in ports:
                probe_env = dict(env, BENCH_REDIS_PORT=ports[variant])
                for case in ["clean", "heavy", "independent", "singleton", "progress"]:
                    sample(engine, variant, case, 4, 2, probe_env)
            emit(engine=engine, smoke="passed")
            for repeat in range(5):
                for case, (jobs, workers) in workloads.items():
                    variants = ["always", "lazy"] if lazy else ["sql", "always", "everysec"]
                    for i in range(len(variants)):
                        variant = variants[(repeat + i) % len(variants)]
                        record = sample(engine, variant, case, jobs, workers,
                                        dict(env, BENCH_REDIS_PORT=ports[variant]))
                        emit(**record, repeat=repeat + 1)


if __name__ == "__main__":
    main()
