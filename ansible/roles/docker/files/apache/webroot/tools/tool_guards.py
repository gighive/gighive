"""Argument and path guards shared by the webroot/tools CLI scripts."""
import argparse
import os
import re
from pathlib import Path
from typing import Optional, Tuple

_ARG_RULES = (
    ("ssh_target", "--ssh-target", r"[A-Za-z0-9_.@-]+"),
    ("db_host", "--db-host", r"[A-Za-z0-9_.:-]+"),
    ("db_user", "--db-user", r"[A-Za-z0-9_.@-]+"),
    ("db_name", "--db-name", r"[A-Za-z0-9_$]+"),
)


def check_remote_args(args: argparse.Namespace) -> None:
    """Exit unless the ssh/mysql connection args are plain host/user/db names (no option injection)."""
    for attr, flag, pattern in _ARG_RULES:
        value = str(getattr(args, attr) or "")
        if not re.fullmatch(pattern, value) or value.startswith("-"):
            raise SystemExit(f"{flag} has unsupported characters: {value!r}")


def inventories_dir() -> Optional[str]:
    """The repo's ansible/inventories folder, when these tools run from a repo checkout."""
    for parent in Path(__file__).resolve().parents:
        cand = parent / "inventories"
        if (cand / "group_vars").is_dir():
            return os.path.realpath(cand)
    return None


def read_group_vars(group_vars_path: str) -> Tuple[Optional[dict], str]:
    """Parse an Ansible group_vars YAML file; only files inside the repo's ansible/inventories are read."""
    try:
        import yaml  # type: ignore
    except Exception:
        return None, "PyYAML not installed"

    inv = inventories_dir()
    real = os.path.realpath(group_vars_path)
    if inv is None or not real.startswith(inv + os.sep):
        return None, f"group_vars must be inside the repo's ansible/inventories folder: {group_vars_path}"
    try:
        with open(real, "r", encoding="utf-8") as f:
            data = yaml.safe_load(f) or {}
    except FileNotFoundError:
        return None, f"group_vars file not found: {group_vars_path}"
    except Exception as e:
        return None, f"failed to parse group_vars: {e}"

    if not isinstance(data, dict):
        return None, "group_vars did not parse to a mapping"
    return data, "ok"
