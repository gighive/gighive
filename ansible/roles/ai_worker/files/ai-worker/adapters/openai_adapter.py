"""
adapters/openai_adapter.py — OpenAI GPT-4.1 vision adapter.
"""

from __future__ import annotations

import base64
import json
import logging
import os
import time

from adapters.base import FrameData, LLMVisionAdapter, TagResult
from openai import OpenAI, RateLimitError, APIStatusError

logger = logging.getLogger(__name__)

ALLOWED_NAMESPACES = {'scene', 'object', 'activity', 'person_role'}

SYSTEM_PROMPT = """\
You are an expert video-content tagger. Given one or more video frame images, \
return a JSON array of tag objects describing the visual content. \
Each object must have exactly these fields:
  namespace : one of "scene", "object", "activity", "person_role"
  name      : short slug (lowercase, underscores, 1-4 words)
  confidence: float 0.0-1.0

Respond ONLY with a valid JSON array. Example:
[{"namespace":"scene","name":"outdoor_concert","confidence":0.9},
 {"namespace":"activity","name":"guitar_playing","confidence":0.85}]
"""


class OpenAIAdapter(LLMVisionAdapter):
    """GPT-4.1 (or compatible) multimodal vision adapter."""

    def __init__(self) -> None:
        api_key = os.getenv('OPENAI_API_KEY', '')
        base_url = os.getenv('LLM_BASE_URL', 'https://api.openai.com/v1')
        self.model = os.getenv('OPENAI_MODEL', 'gpt-4.1')
        self.max_per_chunk = int(os.getenv('AI_MAX_FRAMES_PER_CHUNK', '6'))
        self.client = OpenAI(api_key=api_key, base_url=base_url)

    def _encode_frame(self, path: str) -> str:
        with open(path, 'rb') as fh:
            return base64.b64encode(fh.read()).decode('utf-8')

    def _call_with_retry(self, messages: list, max_retries: int = 4) -> str:
        """Call the API with exponential backoff on RateLimitError."""
        delay = 4.0
        for attempt in range(max_retries):
            try:
                resp = self.client.chat.completions.create(
                    model=self.model,
                    messages=messages,
                    max_tokens=512,
                    temperature=0,
                )
                return resp.choices[0].message.content or ''
            except RateLimitError:
                if attempt == max_retries - 1:
                    raise
                logger.warning("RateLimitError; retrying in %.1fs (attempt %d/%d)", delay, attempt + 1, max_retries)
                time.sleep(delay)
                delay = min(delay * 2, 64.0)
            except APIStatusError as exc:
                if exc.status_code in {500, 502, 503, 529} and attempt < max_retries - 1:
                    logger.warning("API %d error; retrying in %.1fs", exc.status_code, delay)
                    time.sleep(delay)
                    delay = min(delay * 2, 64.0)
                else:
                    raise
        return ''

    def _analyze_chunk(self, chunk: list[FrameData]) -> list[TagResult]:
        """Send one chunk of frames to the LLM and parse the tag array."""
        content: list = [{'type': 'text', 'text': SYSTEM_PROMPT}]
        for frame in chunk:
            b64 = self._encode_frame(frame.path)
            content.append({
                'type': 'image_url',
                'image_url': {'url': f'data:image/jpeg;base64,{b64}', 'detail': 'low'},
            })
        content.append({
            'type': 'text',
            'text': (
                f"These {len(chunk)} images are sampled from the same video "
                f"(timestamps: {[round(f.timestamp_seconds, 1) for f in chunk]}s). "
                "Return the JSON tag array."
            ),
        })

        raw = self._call_with_retry([{'role': 'user', 'content': content}])
        tags = self._parse_response(raw)
        if chunk:
            t_start = chunk[0].timestamp_seconds
            t_end   = chunk[-1].timestamp_seconds
            tags = [
                TagResult(
                    namespace=t.namespace,
                    name=t.name,
                    confidence=t.confidence,
                    start_seconds=t_start,
                    end_seconds=t_end,
                )
                for t in tags
            ]
        return tags

    def _parse_response(self, raw: str) -> list[TagResult]:
        """Parse LLM JSON response into TagResult list. Handles code fences."""
        text = raw.strip()
        if text.startswith('```'):
            lines = text.splitlines()
            text = '\n'.join(lines[1:-1] if lines[-1] == '```' else lines[1:])
        try:
            data = json.loads(text)
        except json.JSONDecodeError:
            logger.warning("Could not parse LLM JSON response: %s", text[:200])
            return []

        results: list[TagResult] = []
        if not isinstance(data, list):
            data = [data] if isinstance(data, dict) else []
        for item in data:
            if not isinstance(item, dict):
                continue
            ns = str(item.get('namespace', '')).strip()
            name = str(item.get('name', '')).strip()
            if not ns or not name:
                continue
            if ns not in ALLOWED_NAMESPACES:
                continue
            try:
                confidence = float(item.get('confidence', 0.5))
            except (ValueError, TypeError):
                confidence = 0.5
            results.append(TagResult(namespace=ns, name=name, confidence=confidence))
        return results

    def analyze_frames(self, frames: list[FrameData], prompt: str = '') -> list[TagResult]:
        """Chunk frames and call the LLM; aggregate and return all TagResults."""
        all_tags: list[TagResult] = []
        for start in range(0, len(frames), self.max_per_chunk):
            chunk = frames[start: start + self.max_per_chunk]
            tags = self._analyze_chunk(chunk)
            logger.debug("Chunk [%d:%d] produced %d tags", start, start + len(chunk), len(tags))
            all_tags.extend(tags)
        return all_tags
