#!/usr/bin/env python3
"""Fail when Clover line coverage is below the configured threshold."""

from __future__ import annotations

import argparse
import xml.etree.ElementTree as ET
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("report", type=Path)
    parser.add_argument("--minimum", type=float, required=True)
    args = parser.parse_args()

    metrics = ET.parse(args.report).getroot().find("project/metrics")
    if metrics is None:
        raise SystemExit(f"Clover metrics not found in {args.report}")

    statements = int(metrics.attrib["statements"])
    covered = int(metrics.attrib["coveredstatements"])
    coverage = 100.0 if statements == 0 else covered * 100.0 / statements
    print(f"Backend domain/application line coverage: {coverage:.2f}% ({covered}/{statements})")

    if coverage < args.minimum:
        print(f"Required coverage: {args.minimum:.2f}%")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
