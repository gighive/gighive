# Feature: Platform Trust & Provenance

Date: 2026-04-25  
Status: Concept / Early Thinking  
Parent framework: [GigHive Intelligence Platform Framework](feature_ai_intelligence_platform.md)

---

## Motivation

GigHive should be a force for good in the world. That starts with a commitment that only authenticated real humans use the platform, and that the media ingested through GigHive carries a verifiable, tamper-evident record of its origin. In an era of deepfakes, synthetic media, and AI-generated disinformation, GigHive has an opportunity to differentiate itself as a platform of authentic human capture.

---

## Three Related but Distinct Concepts

### 1. Provenance / Chain of Custody (C2PA)

Cryptographically proving *who uploaded this video, when, from what authenticated account, and via what platform.*

The live industry standard for this is **C2PA** (Coalition for Content Provenance and Authenticity), backed by Adobe, Microsoft, Google, Intel, BBC, and others. C2PA attaches a signed, tamper-evident manifest to the media file that travels with it. A C2PA-aware viewer can inspect any GigHive-ingested video and see:

> "This was captured and uploaded by a verified human via GigHive on [date] from account [id]."

The manifest is cryptographically signed by GigHive's private key, so any tampering or re-upload is detectable.

**References:**
- [C2PA Specification](https://c2pa.org/)
- [Content Credentials (Adobe CAI implementation)](https://contentcredentials.org/)

### 2. Invisible Digital Watermarking

Steganographically embedding a signal *inside the video pixels and/or audio track* that:

- Survives re-encoding, cropping, compression, and screenshot capture
- Can be detected later to prove GigHive origin even after C2PA metadata is stripped
- Is invisible to the naked eye/ear

This is a complementary layer to C2PA — C2PA lives in metadata (strippable), watermarking lives in the signal itself (much harder to remove without degrading quality).

### 3. AI-Generated Content Detection (at Ingest)

Before a video is fully ingested into the GigHive media library, an AI helper screens it to flag whether it appears to be synthetically generated (deepfake, AI-generated video, manipulated footage). This enforces the "real humans, real captures" platform promise at the gate.

This fits the AI Intelligence Platform framework as a helper: `ai_gen_detector_v1`.

---

## Architectural Placement

| Concept | Where it lives |
|---------|---------------|
| Human authentication requirement | Existing auth system (upload gate) |
| C2PA manifest signing | Upload pipeline — happens at ingest, before AI workers |
| Invisible watermark embedding | Upload pipeline — post-ingest processing step |
| AI-generated content detection | AI Intelligence Platform — `ai_gen_detector_v1` helper |
| Watermark verification helper | AI Intelligence Platform — `watermark_verify_v1` helper |

The first two are **platform trust infrastructure** (broader than the AI platform). The last two plug cleanly into the existing AI worker + helper registry model.

---

## Open Questions / Decisions

- Which C2PA SDK/library to use for signing? (Adobe's `c2patool`, Rust `c2pa-rs`, or a third-party service)
- Where does GigHive's C2PA signing key live and how is it managed/rotated?
- Do we watermark all ingested videos, or only on opt-in / by config per deployment?
- Which AI-gen detection model/service to use for `ai_gen_detector_v1`?
- Should GigHive expose a public C2PA verification endpoint so anyone can verify a video's origin?
- How does this interact with the quickstart bundle (single-instance deployments with no central GigHive signing authority)?

---

## Related

- [GigHive Intelligence Platform Framework](feature_ai_intelligence_platform.md) — parent framework
- [C2PA Specification](https://c2pa.org/)
- [Content Credentials](https://contentcredentials.org/)
