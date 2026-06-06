"""
GigHive MCP Server

Spawned on-demand via SSH by the AI assistant (Windsurf/Cascade, Claude Desktop).
Communicates over stdio. Exits when the session ends — no persistent daemon.

Usage (via SSH from the AI assistant config):
  ssh gighive-server /path/to/mcp-server/venv/bin/python /path/to/mcp-server/server.py
"""

from mcp.server.fastmcp import FastMCP

import tools.ai_pipeline as ai_pipeline
import tools.media_library as media_library
import tools.upload_jobs as upload_jobs_mod
import tools.system as system_mod

mcp = FastMCP("gighive")

ai_pipeline.register(mcp)
media_library.register(mcp)
upload_jobs_mod.register(mcp)
system_mod.register(mcp)

if __name__ == "__main__":
    mcp.run()
