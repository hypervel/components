"""Print matched medians and observed ranges from the verified campaign."""
import json
import statistics
import sys
from collections import defaultdict
from pathlib import Path

from run import IMAGES, WORKLOADS

runs = defaultdict(dict)
for line in Path(sys.argv[1]).read_text().splitlines():
    row = json.loads(line)
    if "seconds" in row:
        group = runs[row["engine"], row["case"], row["variant"]]
        if row["repeat"] in group or not row["seconds"] > 0:
            raise ValueError(f"Invalid sample: {row}")
        group[row["repeat"]] = row

variants = ["always", "lazy"] if any(key[2] == "lazy" for key in runs) else ["sql", "always", "everysec"]
if "lazy" in variants:
    cases = [("pgsql", case) for case in ("clean", "independent", "singleton")]
else:
    cases = [(engine, case) for engine in IMAGES for case in WORKLOADS]

print("| SQL engine | Workload | " + " | ".join(variants) + " |")
print("|---|---|" + "---:|" * len(variants))
for engine, case in cases:
    cells = []
    for variant in variants:
        group = runs[engine, case, variant]
        if set(group) != {1, 2, 3, 4, 5}:
            raise ValueError(f"Incomplete runs: {engine} {case} {variant}")
        rates = [row["jobs"] / row["seconds"] for row in group.values()]
        cells.append(f"{statistics.median(rates):,.0f} ({min(rates):,.0f}–{max(rates):,.0f})")
    print("| " + " | ".join([engine, case, *cells]) + " |")
