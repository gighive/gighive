"""
tools/media_library.py — Media corpus query tools.

Tools:
  search_assets_by_tag      — tag-filtered asset search
  get_events                — events list with asset count and tag coverage
  get_assets_untagged       — assets with zero confirmed taggings
  get_tag_namespace_summary — tag distribution across corpus
"""

from __future__ import annotations

import db


def register(mcp) -> None:

    @mcp.tool()
    def search_assets_by_tag(
        namespace: str | None = None,
        tag_name: str | None = None,
        event_date_from: str | None = None,
        event_date_to: str | None = None,
        limit: int = 50,
    ) -> list[dict]:
        """Search assets filtered by tag namespace/name and optional event date range.

        All parameters are optional. Returns assets with their tags and event context.
        """
        where: list[str] = []
        params: list = []

        if namespace is not None:
            where.append("t.namespace = %s")
            params.append(namespace)
        if tag_name is not None:
            where.append("t.name LIKE %s")
            params.append(f'%{tag_name}%')
        if event_date_from is not None:
            where.append("e.event_date >= %s")
            params.append(event_date_from)
        if event_date_to is not None:
            where.append("e.event_date <= %s")
            params.append(event_date_to)

        where_clause = ("WHERE " + " AND ".join(where)) if where else ""
        params.append(limit)

        rows = db.query(
            f"""SELECT a.asset_id, a.source_relpath, a.duration_seconds, a.file_type,
                       e.event_id, e.org_name AS event_name, e.event_date,
                       GROUP_CONCAT(DISTINCT CONCAT(t.namespace,':',t.name)) AS tags
                FROM assets a
                LEFT JOIN event_items ei ON ei.asset_id = a.asset_id
                LEFT JOIN events e ON e.event_id = ei.event_id
                LEFT JOIN taggings tg ON tg.target_id = a.asset_id AND tg.target_type = 'asset'
                LEFT JOIN tags t ON t.id = tg.tag_id
                {where_clause}
                GROUP BY a.asset_id, a.source_relpath, a.duration_seconds, a.file_type,
                         e.event_id, e.org_name, e.event_date
                ORDER BY e.event_date DESC, a.source_relpath
                LIMIT %s""",
            params,
        )
        return [
            {
                'asset_id':        r['asset_id'],
                'source_relpath':  r.get('source_relpath'),
                'duration_seconds': r.get('duration_seconds'),
                'file_type':       r.get('file_type'),
                'event_name':      r.get('event_name'),
                'event_date':      str(r['event_date']) if r.get('event_date') else None,
                'tags':            r.get('tags'),
            }
            for r in rows
        ]

    @mcp.tool()
    def get_events(
        org_name: str | None = None,
        date_from: str | None = None,
        date_to: str | None = None,
    ) -> list[dict]:
        """Return events with per-event asset count and tag coverage stats.

        untagged_count is derived as asset_count - tagged_count.
        """
        where: list[str] = []
        params: list = []

        if org_name is not None:
            where.append("e.org_name = %s")
            params.append(org_name)
        if date_from is not None:
            where.append("e.event_date >= %s")
            params.append(date_from)
        if date_to is not None:
            where.append("e.event_date <= %s")
            params.append(date_to)

        where_clause = ("WHERE " + " AND ".join(where)) if where else ""

        rows = db.query(
            f"""SELECT e.event_id, e.org_name, e.event_date, e.event_type,
                       COUNT(DISTINCT ei.asset_id) AS asset_count,
                       COUNT(DISTINCT tg.target_id) AS tagged_count
                FROM events e
                LEFT JOIN event_items ei ON ei.event_id = e.event_id
                LEFT JOIN taggings tg ON tg.target_id = ei.asset_id AND tg.target_type = 'asset'
                {where_clause}
                GROUP BY e.event_id, e.org_name, e.event_date, e.event_type
                ORDER BY e.event_date DESC""",
            params,
        )
        return [
            {
                'event_id':       r['event_id'],
                'org_name':       r.get('org_name'),
                'event_date':     str(r['event_date']) if r.get('event_date') else None,
                'event_type':     r.get('event_type'),
                'asset_count':    r['asset_count'],
                'tagged_count':   r['tagged_count'],
                'untagged_count': max(0, r['asset_count'] - r['tagged_count']),
            }
            for r in rows
        ]

    @mcp.tool()
    def get_assets_untagged(limit: int = 100) -> dict:
        """Return video assets with zero confirmed taggings.

        Excludes stub rows (duration_seconds IS NULL) that have no file on disk.
        Returns the asset list and total_untagged count.
        """
        rows = db.query(
            """SELECT a.asset_id, a.source_relpath, a.file_type,
                      e.org_name AS event_name
               FROM assets a
               LEFT JOIN event_items ei ON ei.asset_id = a.asset_id
               LEFT JOIN events e ON e.event_id = ei.event_id
               WHERE a.file_type = 'video'
                 AND a.duration_seconds IS NOT NULL
                 AND NOT EXISTS (
                     SELECT 1 FROM ai_jobs j
                     WHERE j.target_type = 'asset' AND j.target_id = a.asset_id
                       AND j.job_type = 'categorize_video'
                       AND j.status IN ('queued', 'running', 'done')
                 )
               ORDER BY e.event_date DESC, a.source_relpath
               LIMIT %s""",
            [limit],
        )
        serializable = [
            {
                'asset_id':       r['asset_id'],
                'source_relpath': r.get('source_relpath'),
                'file_type':      r.get('file_type'),
                'event_name':     r.get('event_name'),
            }
            for r in rows
        ]
        return {
            'assets':        serializable,
            'total_untagged': len(serializable),
        }

    @mcp.tool()
    def get_tag_namespace_summary(namespace: str | None = None) -> list[dict]:
        """Return tag distribution across the corpus grouped by namespace and name.

        Pass namespace to filter to a single namespace, or omit for the full corpus.
        """
        where = "WHERE t.namespace = %s" if namespace is not None else ""
        params = [namespace] if namespace is not None else []

        rows = db.query(
            f"""SELECT t.namespace, t.name,
                       COUNT(tg.id) AS usage_count
                FROM tags t
                LEFT JOIN taggings tg ON tg.tag_id = t.id
                {where}
                GROUP BY t.namespace, t.name
                ORDER BY t.namespace, t.name""",
            params,
        )
        return [
            {
                'namespace':   r['namespace'],
                'name':        r['name'],
                'usage_count': r['usage_count'],
            }
            for r in rows
        ]
