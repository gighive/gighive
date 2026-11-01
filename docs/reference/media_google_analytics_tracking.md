# Media Tracking in Google Analytics 4 (GA4)

## Overview

Google Analytics 4 (GA4) does **not** automatically track static media
file requests such as MP3 or MP4 files served directly from a web
server. GA only records data when:

1.  A page loads with the GA tracking tag installed, or
2.  A tracked event is explicitly triggered (e.g., click, play,
    download).

Because audio and video files are static assets and do not execute
JavaScript, they will not appear in the **Pages and Screens** report
unless additional tracking is implemented.

------------------------------------------------------------------------

# Why Media Files Do Not Appear in GA4

If users access files like:

/media/song.mp3 /videos/jam.mp4

GA4 will not record them because:

-   Media files do not contain the GA tracking script
-   Static file requests do not trigger JavaScript
-   Direct downloads bypass event tracking

As a result, only PHP/HTML pages (e.g., `/`, `/index.php`, `/loops.php`)
appear in GA4 by default.

------------------------------------------------------------------------

# Media Tracking Options

Below are three recommended approaches for tracking audio and video
activity.

------------------------------------------------------------------------

## Option 1 --- Track Clicks on Media Links (Simple & Recommended)

If users download media via anchor links:

`<a href="/media/song.mp3">`{=html}Download`</a>`{=html}

Add the following JavaScript to track downloads:

```{=html}
<script>
document.addEventListener('click', function(e) {
  const link = e.target.closest('a');
  if (!link) return;

  if (link.href.match(/\.(mp3|mp4)$/i)) {
    gtag('event', 'file_download', {
      file_name: link.href,
      file_type: link.href.split('.').pop()
    });
  }
});
</script>
```
### Result

-   GA4 will record an event named `file_download`
-   File name and type are captured as parameters
-   Events are visible under **Reports → Engagement → Events**

------------------------------------------------------------------------

## Option 2 --- Track Audio/Video Play Events (Engagement Tracking)

If media is embedded using HTML5 players:

```{=html}
<audio controls src="/media/song.mp3">
```
```{=html}
</audio>
```
```{=html}
<video controls src="/videos/jam.mp4">
```
```{=html}
</video>
```
Add this JavaScript to track play events:

```{=html}
<script>
document.querySelectorAll('audio, video').forEach(function(media) {
  media.addEventListener('play', function() {
    gtag('event', 'media_play', {
      file_name: media.currentSrc
    });
  });
});
</script>
```
### Result

-   GA4 records a `media_play` event
-   Tracks when users actually press play
-   More accurate for engagement metrics

------------------------------------------------------------------------

## Option 3 --- Analyze Apache Server Logs (Most Accurate for Downloads)

Because the platform runs on Apache, every media request is logged in:

/var/log/apache2/access.log

Example entries:

GET /media/song.mp3 GET /loops/loop01.mp4

### Advantages

-   Tracks all downloads (even if JavaScript is blocked)
-   Works for direct URL access
-   Provides authoritative download counts

### Tradeoffs

-   Requires log parsing or analytics tooling
-   Not integrated automatically into GA4

------------------------------------------------------------------------

# Recommended Architecture for a Music Platform

## Recommended Event Strategy

Track the following events in GA4:

-   `media_play`
-   `file_download`
-   `loop_play`

## Suggested Custom Parameters

Include structured metadata in events such as:

-   song_name
-   genre
-   jam_session_id
-   musician
-   media_type (audio/video/loop)

This enables richer reporting and segmentation inside GA4.

------------------------------------------------------------------------

# Important GA4 Behavior Notes

GA4 does NOT automatically track:

-   MP3 downloads
-   MP4 downloads
-   Direct file hits
-   Directory browsing

Even with Enhanced Measurement enabled, GA4 only tracks link clicks ---
not file requests themselves.

Media files should not be treated as "pages" because that would:

-   Inflate page view metrics
-   Distort engagement time
-   Skew session analytics

------------------------------------------------------------------------

# Summary

  Goal                       Recommended Method
  -------------------------- ----------------------
  Track downloads            Click event tracking
  Track engagement           HTML5 play events
  Get authoritative counts   Apache log analysis

For production-grade tracking, use:

1.  GA4 event tracking for engagement insights
2.  Apache log validation for download accuracy
3.  Structured metadata parameters for analytics depth

------------------------------------------------------------------------

End of Document
