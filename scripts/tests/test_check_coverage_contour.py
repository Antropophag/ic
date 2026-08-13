from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from scripts import check_coverage_contour


class CheckCoverageContourTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        (self.root / "backend/src/Application").mkdir(parents=True)
        (self.root / "backend/tests/Integration/Request").mkdir(parents=True)
        (self.root / "backend/src/Application/Existing.php").write_text("<?php\n", encoding="utf-8")
        (self.root / "backend/tests/Integration/Request/ExistingTest.php").write_text(
            "<?php\n", encoding="utf-8"
        )
        self.write_config()
        (self.root / "sonar-project.properties").write_text(
            "sonar.sources=.\n"
            "sonar.tests=.\n"
            "sonar.test.inclusions=backend/tests/**\n"
            "sonar.exclusions=backend/tests/**\n"
            "sonar.coverage.exclusions=backend/migrations/**,backend/tools/architecture-guard.php,backend/tools/architecture-baseline.php\n",
            encoding="utf-8",
        )

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def write_config(
        self,
        source: str = "<directory>src</directory>",
        tests: str = "<directory>tests/Integration</directory>",
    ) -> None:
        (self.root / "backend/phpunit.coverage.xml").write_text(
            f"""<?xml version="1.0"?>
<phpunit>
  <testsuites><testsuite name="covered-integration">{tests}</testsuite></testsuites>
  <source><include>{source}</include></source>
</phpunit>
""",
            encoding="utf-8",
        )

    def test_new_production_and_integration_files_are_discovered_automatically(self) -> None:
        (self.root / "backend/src/Application/NewProduction.php").write_text("<?php\n", encoding="utf-8")
        (self.root / "backend/tests/Integration/Request/NewBehaviorTest.php").write_text(
            "<?php\n", encoding="utf-8"
        )

        self.assertEqual([], check_coverage_contour.validate(self.root))

    def test_manual_file_contours_are_rejected_as_stale(self) -> None:
        self.write_config(
            source="<file>src/Application/Existing.php</file>",
            tests="<file>tests/Integration/Request/ExistingTest.php</file>",
        )

        errors = check_coverage_contour.validate(self.root)

        self.assertTrue(any("coverage source must be" in error for error in errors))
        self.assertTrue(any("covered-integration must use" in error for error in errors))

    def test_manual_group_exception_is_rejected(self) -> None:
        config = self.root / "backend/phpunit.coverage.xml"
        config.write_text(
            config.read_text(encoding="utf-8").replace(
                "<source>",
                "<groups><exclude><group>slow</group></exclude></groups><source>",
            ),
            encoding="utf-8",
        )

        errors = check_coverage_contour.validate(self.root)

        self.assertTrue(any("must not have manual group exclusions" in error for error in errors))

    def test_blanket_sonar_coverage_exclusion_is_rejected(self) -> None:
        sonar = self.root / "sonar-project.properties"
        sonar.write_text(
            sonar.read_text(encoding="utf-8").replace(
                "backend/migrations/**,backend/tools/architecture-guard.php",
                "backend/src/Infrastructure/**,backend/tools/architecture-guard.php",
            ),
            encoding="utf-8",
        )

        errors = check_coverage_contour.validate(self.root)

        self.assertTrue(any("stale or blanket exclusions" in error for error in errors))


if __name__ == "__main__":
    unittest.main()
