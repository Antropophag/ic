from __future__ import annotations

import io
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

from scripts import check_coverage


class CheckCoverageTest(unittest.TestCase):
    def run_gate(self, report: str, minimum: str) -> tuple[int, str]:
        with tempfile.TemporaryDirectory() as directory:
            report_path = Path(directory) / "clover.xml"
            report_path.write_text(report, encoding="utf-8")
            output = io.StringIO()
            with patch.object(
                sys,
                "argv",
                ["check_coverage.py", str(report_path), "--minimum", minimum],
            ), redirect_stdout(output):
                result = check_coverage.main()
        return result, output.getvalue()

    def test_infrastructure_coverage_does_not_inflate_domain_gate(self) -> None:
        result, output = self.run_gate(
            """<?xml version="1.0"?>
<coverage><project>
  <file name="/app/src/Domain/Example.php"><metrics statements="10" coveredstatements="9"/></file>
  <file name="/app/src/Infrastructure/Example.php"><metrics statements="100" coveredstatements="100"/></file>
</project></coverage>
""",
            "91",
        )

        self.assertEqual(1, result)
        self.assertIn("90.00% (9/10)", output)
        self.assertIn("Required coverage: 91.00%", output)

    def test_application_and_domain_metrics_are_combined(self) -> None:
        result, output = self.run_gate(
            """<?xml version="1.0"?>
<coverage><project>
  <file name="src/Application/Example.php"><metrics statements="4" coveredstatements="4"/></file>
  <file name="src\\Domain\\Example.php"><metrics statements="6" coveredstatements="5"/></file>
</project></coverage>
""",
            "90",
        )

        self.assertEqual(0, result)
        self.assertIn("90.00% (9/10)", output)

    def test_report_without_domain_or_application_metrics_fails(self) -> None:
        with self.assertRaisesRegex(SystemExit, "Domain/Application Clover metrics not found"):
            self.run_gate(
                """<?xml version="1.0"?>
<coverage><project>
  <file name="/app/src/Infrastructure/Example.php"><metrics statements="1" coveredstatements="1"/></file>
</project></coverage>
""",
                "90",
            )


if __name__ == "__main__":
    unittest.main()
