"""
adapters/base.py — Abstract LLM vision adapter interface and shared data types.
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass, field


@dataclass
class FrameData:
    """A single extracted video frame and its metadata."""
    path: str
    timestamp_seconds: float
    derived_asset_id: int


@dataclass
class TagResult:
    """A single tag produced by an LLM adapter."""
    namespace: str
    name: str
    confidence: float = 0.5
    start_seconds: float | None = None
    end_seconds: float | None = None


class LLMVisionAdapter(ABC):
    """Abstract interface all LLM vision providers must implement."""

    @abstractmethod
    def analyze_frames(
        self,
        frames: list[FrameData],
        prompt: str,
    ) -> list[TagResult]:
        """
        Send frames to the LLM and return a list of TagResult objects.

        Implementations must handle chunking internally if needed
        (e.g. AI_MAX_FRAMES_PER_CHUNK env var).
        """
        ...
