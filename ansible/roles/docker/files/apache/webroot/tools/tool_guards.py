"""Argument and path guards shared by the webroot/tools CLI scripts."""
import argparse
import os
import re
from pathlib import Path
from typing import Optional

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
