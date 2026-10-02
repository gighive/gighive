# GigHive iPhone App — Marketing Video Plan

## Target Audiences

1. **Operators/Self-hosters** — DIY tech folks running Docker, anti-Big-Tech, want to own their media
2. **End users** — fans and wedding guests using the iPhone app to upload music/video

For a 20-30s iPhone app marketing video, the primary target is **fans at live music events** (first video). Secondary audiences deferred to future cuts:
- Wedding photographers/planners/couples
- Concert/event organizers

---

## Script Arc (20-30s)

| Time | Beat |
|------|------|
| 0-3s | **Hook** — *"At the show last night — who got the best footage?"* |
| 3-15s | **Show the app in action** — fan opens GigHive, selects the band's event, uploads their clip; band's dashboard lights up with the new upload in real time |
| 15-25s | **The payoff** — VO: *"Now it lives on the band's own site. Not buried in your camera roll. Not owned by Big Tech."* |
| 25-30s | **CTA** — App Store badge + URL |

---

## Source Clips Available

### Band / Live Show Footage
- `videos/bandsShows/blackelk/[1-9].avi` — 9 clips
- `videos/bandsShows/hazelmotes/HazelMotes1990McGoverns.mp4`
- `videos/thenic/shows/shShow20111014/full.mp4`
- `videos/stormpigs/unusedJams/20060812/Cap0001(0016).m2t`
- https://dev.gighive.app/video/e4f151a12a19807dfb900f9616da8a800871a310ade0e9831406a0489780d2d9.mp4


### App Demo
- `videos/projects/gighive/iphonetutorial/iphonetutorial.mp4` — full tutorial (total ~95s)
  - Upload flow demo: use as-is for the 3-15s beat
  - Dashboard upload arrival: `84s-95s` (use last 3-5s) for the 15-25s payoff beat

---

## Tool Chain (ffmpeg-based)

- Assemble + trim clips with `ffmpeg`
- Add text overlays (follow `4_add_text.sh` patterns)
- Layer music (follow `6_layerAudio.sh` patterns)
- Final concat (follow `5_concat.sh` patterns)

---

## Next Steps

- [x] Decide on audience angle to lead with — **band fans** (first video)
- [x] Drop video links for available source clips
- [ ] Map clips to script arc
- [ ] Build ffmpeg pipeline
