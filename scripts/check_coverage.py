#!/usr/bin/env python3
"""Fail when Clover statement coverage is invalid or below the threshold."""

from __future__ import annotations

import argparse
import xml.etree.ElementTree as ET
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("report", type=Path)
    parser.add_argument("--minimum", type=float, required=True)
    args = parser.parse_args()

    root = ET.parse(args.report).getroot()
    source_prefixes = ("src/Domain/", "src/Application/")
    metrics = []
    for file_node in root.findall(".//file"):
        path = file_node.attrib.get("name", "").replace("\\", "/").lstrip("/")
        if not any(path.startswith(prefix) or f"/{prefix}" in path for prefix in source_prefixes):
            continue
        metrics.append(file_node.find("metrics"))
    metrics = [metric for metric in metrics if metric is not None]
    if not metrics:
        raise SystemExit(f"Domain/Application Clover metrics not found in {args.report}")

    statements = sum(int(metric.attrib["statements"]) for metric in metrics)
    covered = sum(int(metric.attrib["coveredstatements"]) for metric in metrics)
    if statements <= 0:
        raise SystemExit(
            "Clover report contains no statements; coverage collection is likely misconfigured"
        )
    if covered < 0 or covered > statements:
        raise SystemExit(
            f"Invalid Clover metrics: covered statements {covered}, total statements {statements}"
        )

    coverage = covered * 100.0 / statements
    print(
        "Backend domain/application statement coverage: "
        f"{coverage:.2f}% ({covered}/{statements})"
    )

    if coverage < args.minimum:
        print(f"Required coverage: {args.minimum:.2f}%")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
