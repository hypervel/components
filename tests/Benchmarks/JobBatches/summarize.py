"""Summarize aggregate jobs/s, ranges and write volume from run.py JSON output."""
import argparse
import collections
import json
from pathlib import Path
from statistics import median

parser = argparse.ArgumentParser()
parser.add_argument("files", nargs="+", type=Path)
args = parser.parse_args()
groups = collections.defaultdict(list)
for path in args.files:
    for line in path.read_text().splitlines():
        sample = json.loads(line)
        if "seconds" in sample:
            key = tuple(sample[k] for k in ("phase", "engine", "case", "variant"))
            groups[key].append(sample)

print("| Phase | Engine | Case | Variant | Runs | Jobs/s median (range) | SQL/job | Tx ms/job | Lock response to commit ack ms/job¹ | Log B/job² |")
print("|---|---|---|---|---:|---:|---:|---:|---:|---:|")
for key, samples in groups.items():
    rate = [s["jobs"]/s["seconds"] for s in samples]
    metrics = [median(s[field] for s in samples) for field in
               ["sql_per_job", "tx_ms_per_job", "parent_lock_lower_bound_ms_per_job", "log_bytes_per_job"]]
    last = f"{metrics[3]:,.0f}" if key[1] != "mariadb" else "—"
    print(f"| {' | '.join(key)} | {len(samples)} | {median(rate):,.1f} ({min(rate):,.1f}–{max(rate):,.1f}) | "
          f"{metrics[0]:.2f} | {metrics[1]:.2f} | {metrics[2]:.2f} | {last} |")
print("\n¹ Mean time per job from return of the locking SQL execution to commit completion, median across runs. "
      "A client interval, not server lock hold time: the server can release its lock before the commit acknowledgment arrives. Tx time includes waits.")
print("\n² MySQL full-row binary log, or PostgreSQL WAL LSN delta. Different mechanisms; compare variants within one engine.")
