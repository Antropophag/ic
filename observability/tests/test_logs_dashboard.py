import json
import re
import unittest
from pathlib import Path


DASHBOARD = (
    Path(__file__).parents[1] / "grafana" / "dashboards" / "logs.json"
)
MATCHER = re.compile(r'\w+=~"([^"]*)"')


class LogsDashboardTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.dashboard = json.loads(DASHBOARD.read_text(encoding="utf-8"))
        cls.variables = {
            variable["name"]: variable
            for variable in cls.dashboard["templating"]["list"]
        }
        cls.expression = cls.dashboard["panels"][0]["targets"][0]["expr"]

    def test_all_values_do_not_match_empty_strings(self):
        for name in ("environment", "service"):
            pattern = self.variables[name]["allValue"]
            with self.subTest(variable=name):
                self.assertIsNone(
                    re.fullmatch(pattern, ""),
                    f"{name} All value must not match an empty string",
                )

    def test_supported_filter_combinations_have_a_non_empty_matcher(self):
        all_environment = self.variables["environment"]["allValue"]
        all_service = self.variables["service"]["allValue"]
        combinations = (
            (all_environment, all_service),
            ("dev", all_service),
            (all_environment, "backend"),
            ("dev", "backend"),
        )

        for environment, service in combinations:
            expression = self.expression.replace(
                "$environment", environment
            ).replace("$service", service)
            patterns = MATCHER.findall(expression)
            with self.subTest(environment=environment, service=service):
                self.assertTrue(patterns, "LogQL selector must contain matchers")
                self.assertTrue(
                    any(re.fullmatch(pattern, "") is None for pattern in patterns),
                    f"LogQL selector is empty-compatible: {expression}",
                )


if __name__ == "__main__":
    unittest.main()
