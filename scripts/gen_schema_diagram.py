#!/usr/bin/env python3
"""Generate docs/data-model.md ER diagram from the migrated MariaDB schema."""

import argparse
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DOC = ROOT / "docs" / "data-model.md"
START = "<!-- schema-diagram:start -->"
END = "<!-- schema-diagram:end -->"
QUERY = r"""
SELECT c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE,
       c.COLUMN_KEY, COALESCE(k.REFERENCED_TABLE_NAME, ''),
       COALESCE(k.REFERENCED_COLUMN_NAME, '')
FROM information_schema.COLUMNS c
LEFT JOIN information_schema.KEY_COLUMN_USAGE k
  ON k.TABLE_SCHEMA = c.TABLE_SCHEMA
 AND k.TABLE_NAME = c.TABLE_NAME
 AND k.COLUMN_NAME = c.COLUMN_NAME
 AND k.REFERENCED_TABLE_NAME IS NOT NULL
WHERE c.TABLE_SCHEMA = DATABASE()
ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION
""".strip()


def read_schema():
    """Read the database through its own client, using credentials inside the container."""
    command = [
        "docker", "compose", "exec", "-T", "mariadb", "sh", "-lc",
        'mariadb --batch --skip-column-names '
        '--user="$MARIADB_USER" --password="$MARIADB_PASSWORD" '
        '"$MARIADB_DATABASE"',
    ]
    result = subprocess.run(
        command,
        cwd=ROOT,
        input=QUERY,
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode:
        sys.exit("Cannot inspect MariaDB. Start the stack and apply migrations first.\n" + result.stderr)

    tables = {}
    for line in result.stdout.splitlines():
        table, column, kind, nullable, key, parent, parent_column = line.split("\t")
        tables.setdefault(table, []).append(
            (column, kind, nullable == "NO", key == "PRI", parent, parent_column)
        )
    return tables


def diagram(tables):
    lines = [
        "<!-- generated from migrated MariaDB by scripts/gen_schema_diagram.py -->",
        "",
        "```mermaid",
        "erDiagram",
    ]
    for table, columns in tables.items():
        lines.append(f"    {table} {{")
        for column, kind, required, primary, parent, parent_column in columns:
            marks = ",".join(filter(None, ["PK" if primary else "", "FK" if parent else ""]))
            note = f' "-> {parent}.{parent_column}"' if parent else ""
            lines.append(f"        {kind.replace(' ', '_')} {column}{' ' + marks if marks else ''}{note}")
        lines.append("    }")
    for table, columns in tables.items():
        for column, _, required, _, parent, _ in columns:
            if parent:
                lines.append(f'    {parent} {"||" if required else "|o"}--o{{ {table} : "{column}"')
    lines.append("```")
    return "\n".join(lines)


def splice(document, generated):
    head, marker, rest = document.partition(START)
    if not marker:
        sys.exit(f"{DOC}: missing {START}")
    _, marker, tail = rest.partition(END)
    if not marker:
        sys.exit(f"{DOC}: missing {END}")
    return f"{head}{START}\n\n{generated}\n\n{END}{tail}"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    current = DOC.read_text(encoding="utf-8")
    updated = splice(current, diagram(read_schema()))
    if args.check and current != updated:
        sys.exit("docs/data-model.md is stale; run `make schema-diagram`")
    if not args.check and current != updated:
        DOC.write_text(updated, encoding="utf-8")
        print("updated docs/data-model.md")


if __name__ == "__main__":
    main()
