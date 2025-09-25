# A) Headers (range support, content type, caching)
curl -I -L "https://www.stormpigs.com/StormPigs20161207_1_whilemyguitargentlyweeps.mp4"

# B) Range request (should be 206 Partial Content)
curl -s -D - -H "Range: bytes=0-102399" \
"https://www.stormpigs.com/StormPigs20161207_1_whilemyguitargentlyweeps.mp4" -o /dev/null

# C) TTFB + total time
curl -o /dev/null -s -w "TTFB:%{time_starttransfer}s total:%{time_total}s bytes:%{size_download}\n" \
"https://www.stormpigs.com/StormPigs20161207_1_whilemyguitargentlyweeps.mp4"

# D) Moov atom placement (faststart)
ffprobe -v trace "/path/to/local/copy.mp4" 2>&1 | egrep "moov|mdat"   # (run on a local copy)
