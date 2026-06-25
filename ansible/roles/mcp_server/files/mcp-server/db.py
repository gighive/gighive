"""
db.py — MySQL connection helper for the GigHive MCP server.

Loads credentials from the host .env file via python-dotenv.
Connects to 127.0.0.1:3306 — the Docker-exposed MySQL port on the host.
DB_HOST in the .env is the Docker container name and is not resolvable here.
"""

import os

import mysql.connector
from dotenv import load_dotenv

import config

load_dotenv(config.ENV_FILE)


def _connect() -> mysql.connector.MySQLConnection:
    return mysql.connector.connect(
        host=config.MYSQL_HOST,
        port=config.MYSQL_PORT,
        database=os.getenv('MYSQL_DATABASE', 'media_db'),
        user=os.getenv('MYSQL_USER', 'appuser'),
        password=os.getenv('MYSQL_PASSWORD', ''),
        charset='utf8mb4',
        autocommit=True,
        connection_timeout=10,
    )


def query(sql: str, params: list | None = None) -> list[dict]:
    """Execute a SELECT and return all rows as a list of dicts."""
    conn = _connect()
    cur = conn.cursor(dictionary=True)
    try:
        cur.execute(sql, params or [])
        return cur.fetchall()
    finally:
        cur.close()
        conn.close()


def query_one(sql: str, params: list | None = None) -> dict | None:
    """Execute a SELECT and return the first row as a dict, or None."""
    rows = query(sql, params)
    return rows[0] if rows else None


def execute(sql: str, params: list | None = None) -> int:
    """Execute a DML statement; return rows affected."""
    conn = _connect()
    conn.autocommit = False
    cur = conn.cursor()
    try:
        cur.execute(sql, params or [])
        conn.commit()
        return cur.rowcount
    except Exception:
        conn.rollback()
        raise
    finally:
        cur.close()
        conn.close()
