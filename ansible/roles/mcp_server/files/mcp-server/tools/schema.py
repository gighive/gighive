"""
tools/schema.py — Database schema inspection and read-only query tools.

Tools:
  get_schema_tables — list all tables in the database
  get_table_ddl     — full SHOW CREATE TABLE DDL for a single table
  execute_select    — run an arbitrary read-only SELECT or CTE (hard cap 700 rows)
"""

from __future__ import annotations

import datetime
import re

import db


_MAX_LIMIT = 700


def _serialize(value):
    """Convert non-JSON-serializable MySQL types to JSON-safe equivalents."""
    if isinstance(value, (datetime.date, datetime.datetime, datetime.timedelta)):
        return str(value)
    if isinstance(value, bytes):
        return value.decode('utf-8', errors='replace')
    return value


def register(mcp) -> None:

    @mcp.tool()
    def get_schema_tables() -> list[dict]:
        """List all tables in the database.

        Returns table names from INFORMATION_SCHEMA sorted alphabetically.
        """
        rows = db.query(
            "SELECT TABLE_NAME AS table_name"
            " FROM INFORMATION_SCHEMA.TABLES"
            " WHERE TABLE_SCHEMA = DATABASE()"
            " ORDER BY TABLE_NAME"
        )
        return [{'table_name': r['table_name']} for r in rows]

    @mcp.tool()
    def get_table_ddl(table_name: str) -> dict:
        """Return the full SHOW CREATE TABLE DDL for a single table.

        Validates table_name against INFORMATION_SCHEMA before executing to
        prevent identifier injection. Returns {table_name, ddl} or {error}.
        """
        exists = db.query_one(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES"
            " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            [table_name],
        )
        if not exists:
            return {'error': f"Table '{table_name}' not found in current database."}

        row = db.query_one(f"SHOW CREATE TABLE `{table_name}`")
        if not row:
            return {'error': f"SHOW CREATE TABLE returned no rows for '{table_name}'."}

        return {
            'table_name': table_name,
            'ddl': row['Create Table'],
        }

    @mcp.tool()
    def execute_select(sql: str, limit: int = 200) -> dict:
        """Run an arbitrary read-only SELECT or CTE against the database.

        Safety constraints:
        - First token must be SELECT or WITH (CTEs); all other statements rejected
        - limit is clamped to a hard maximum of 700 rows
        - If no LIMIT clause is present in sql, LIMIT {limit} is appended
        - All result values are serialised to JSON-safe types
        - MySQL errors are caught and returned as {error} rather than raised

        Returns {rows, row_count, truncated} where truncated: true indicates
        row_count reached the cap and there may be more rows.
        """
        first_token = sql.strip().split()[0].lower() if sql.strip() else ''
        if first_token not in ('select', 'with'):
            return {
                'error': "Only SELECT statements and CTEs (WITH ... SELECT) are permitted.",
                'rows': [],
                'row_count': 0,
                'truncated': False,
            }

        limit = min(max(1, limit), _MAX_LIMIT)

        has_limit = bool(re.search(r'\bLIMIT\b', sql, re.IGNORECASE))
        capped_sql = (
            sql.rstrip().rstrip(';')
            if has_limit
            else f"{sql.rstrip().rstrip(';')} LIMIT {limit}"
        )

        try:
            rows = db.query(capped_sql)
        except Exception as e:
            return {
                'error': str(e),
                'rows': [],
                'row_count': 0,
                'truncated': False,
            }

        serialized = [{k: _serialize(v) for k, v in row.items()} for row in rows]
        row_count = len(serialized)

        return {
            'rows': serialized,
            'row_count': row_count,
            'truncated': not has_limit and row_count == limit,
        }
