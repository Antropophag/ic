#!/usr/bin/env python3
"""Keep PHPUnit's coverage contour aligned with Sonar and repository layout."""

from __future__ import annotations

import argparse
import fnmatch
import xml.etree.ElementTree as ET
from pathlib import Path


EXPECTED_SONAR_COVERAGE_EXCLUSIONS = (
    "backend/migrations/**",
    "backend/tools/architecture-guard.php",
    "backend/tools/architecture-baseline.php",
)


def properties(path: Path) -> dict[str, str]:
    result: dict[str, str] = {}
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if line and not line.startswith("#") and "=" in line:
            key, value = line.split("=", 1)
            result[key.strip()] = value.strip()
    return result


def patterns(value: str) -> tuple[str, ...]:
    return tuple(pattern.strip() for pattern in value.split(",") if pattern.strip())


def matches(path: str, candidates: tuple[str, ...]) -> bool:
    return any(fnmatch.fnmatchcase(path, candidate) for candidate in candidates)


def is_sonar_source(path: str, candidates: tuple[str, ...]) -> bool:
    return any(
        candidate == "."
        or path == candidate.rstrip("/")
        or path.startswith(f"{candidate.rstrip('/')}/")
        for candidate in candidates
    )


def xml_paths(parent: ET.Element, backend: Path, tag: str, suffix: str) -> set[Path]:
    paths: set[Path] = set()
    for node in parent.findall(tag):
        if not node.text or not node.text.strip():
            continue
        candidate = backend / node.text.strip()
        if tag == "directory":
            paths.update(candidate.rglob(f"*{suffix}"))
        elif candidate.is_file():
            paths.add(candidate)
    return {path.resolve() for path in paths}


def validate(root: Path) -> list[str]:
    root = root.resolve()
    backend = root / "backend"
    config_path = backend / "phpunit.coverage.xml"
    tree = ET.parse(config_path)
    errors: list[str] = []

    source = tree.find("source/include")
    if source is None:
        return ["phpunit.coverage.xml has no <source><include> contour"]
    if tree.find("source/exclude") is not None:
        errors.append("coverage source must not have manual <exclude> entries")
    source_directory_nodes = source.findall("directory")
    source_directories = [node.text.strip() for node in source_directory_nodes if node.text]
    source_files = source.findall("file")
    if (
        source_directories != ["src"]
        or source_files
        or any(node.attrib for node in source_directory_nodes)
    ):
        errors.append(
            "coverage source must be the unfiltered directory 'src'; manual files or "
            "directory filters are stale"
        )

    suites = {
        suite.attrib.get("name", ""): suite for suite in tree.findall("testsuites/testsuite")
    }
    integration = suites.get("covered-integration")
    if integration is None:
        errors.append("phpunit.coverage.xml has no covered-integration suite")
        return errors
    integration_directory_nodes = integration.findall("directory")
    integration_directories = [
        node.text.strip() for node in integration_directory_nodes if node.text
    ]
    if (
        integration_directories != ["tests/Integration"]
        or integration.findall("file")
        or any(node.attrib for node in integration_directory_nodes)
    ):
        errors.append(
            "covered-integration must use the unfiltered tests/Integration directory; "
            "manual files or directory filters are stale"
        )
    if integration.findall("exclude"):
        errors.append("covered-integration must not have manual <exclude> entries")

    if tree.find("groups") is not None:
        errors.append("coverage integration discovery must not have manual group filters")

    sonar = properties(root / "sonar-project.properties")
    sonar_sources = patterns(sonar.get("sonar.sources", ""))
    sonar_exclusions = patterns(sonar.get("sonar.exclusions", ""))
    sonar_test_inclusions = patterns(sonar.get("sonar.test.inclusions", ""))
    sonar_coverage_exclusions = patterns(sonar.get("sonar.coverage.exclusions", ""))
    if sonar_coverage_exclusions != EXPECTED_SONAR_COVERAGE_EXCLUSIONS:
        errors.append(
            "Sonar coverage exclusions must contain only migrations and the two "
            "architecture guard files; stale or blanket exclusions are forbidden"
        )
    production = {path.resolve() for path in (backend / "src").rglob("*.php")}
    sonar_production = {
        path
        for path in production
        if is_sonar_source(path.relative_to(root).as_posix(), sonar_sources)
        and not matches(path.relative_to(root).as_posix(), sonar_exclusions)
    }
    covered_source = xml_paths(source, backend, "directory", ".php") | xml_paths(
        source, backend, "file", ".php"
    )
    for path in sorted(sonar_production - covered_source):
        errors.append(f"Sonar production file is outside PHPUnit coverage source: {path.relative_to(root)}")

    integration_tests = {
        path.resolve() for path in (backend / "tests/Integration").rglob("*Test.php")
    }
    for path in sorted(integration_tests):
        relative = path.relative_to(root).as_posix()
        if not matches(relative, sonar_test_inclusions):
            errors.append(f"integration test is outside Sonar test inclusions: {relative}")

    discovered_tests = xml_paths(integration, backend, "directory", "Test.php") | xml_paths(
        integration, backend, "file", "Test.php"
    )
    for path in sorted(integration_tests - discovered_tests):
        errors.append(f"coverage-relevant integration test is not discovered: {path.relative_to(root)}")

    return errors


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parent.parent)
    args = parser.parse_args()
    errors = validate(args.root)
    if errors:
        print("Coverage contour violations:")
        for error in errors:
            print(f"- {error}")
        return 1
    print("Coverage contour is self-maintaining: backend/src and relevant Integration tests are discovered.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
