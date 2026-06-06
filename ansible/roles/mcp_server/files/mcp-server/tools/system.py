"""
tools/system.py — Host environment inspection tool.

Tools:
  get_env_container_subset — read safe env vars from the host .env file
"""

from __future__ import annotations

import os

from dotenv import dotenv_values

import config

_ALLOWED_PREFIXES = ('AI_', 'TUS_')
_ALLOWED_EXACT    = frozenset({'DB_HOST'})


def register(mcp) -> None:

    @mcp.tool()
    def get_env_container_subset(keys: list[str]) -> dict:
        """Read a subset of env vars from the host .env file.

        Only keys matching allowed prefixes (AI_, TUS_) or exact names (DB_HOST)
        are returned. Secrets such as OPENAI_API_KEY and MYSQL_PASSWORD are never
        exposed regardless of what is passed in keys[].

        Returns a dict of {key: value} for each allowed requested key found in .env.
        """
        env = dotenv_values(config.ENV_FILE)

        result: dict[str, str | None] = {}
        rejected: list[str] = []

        for key in keys:
            allowed = (
                any(key.startswith(pfx) for pfx in _ALLOWED_PREFIXES)
                or key in _ALLOWED_EXACT
            )
            if allowed:
                result[key] = env.get(key)
            else:
                rejected.append(key)

        return {
            'values':   result,
            'rejected': rejected,
        }
