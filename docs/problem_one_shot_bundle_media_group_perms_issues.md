# Problem: one-shot bundle media upload group and permission issues

## Summary

When using `upload_media_by_hash.py` from a MacBook against a locally running one-shot bundle on the Pop!_OS development box, media copy initially failed even though the one-shot bundle itself was running correctly.

The root causes were a combination of:

- targeting the wrong SSH user and default remote media directories
- writing into the runtime one-shot bundle under `/tmp/gighive-one-shot-bundle`, where `_host_audio` and `_host_video` were owned by `www-data:www-data`
- the host user `sodo` not initially being in the `www-data` group
- the `_host_video/thumbnails` directory lacking group write permission, which caused thumbnail generation to fail even after media copy started working

## Environment and layout

The running one-shot bundle instance was under:

- `/tmp/gighive-one-shot-bundle`

The relevant runtime bind-mount directories were:

- `/tmp/gighive-one-shot-bundle/_host_audio`
- `/tmp/gighive-one-shot-bundle/_host_video`

`docker-compose.yml` uses:

```yaml
- "${GIGHIVE_AUDIO_DIR:-./_host_audio}:/var/www/html/audio"
- "${GIGHIVE_VIDEO_DIR:-./_host_video}:/var/www/html/video"
```

So if `GIGHIVE_AUDIO_DIR` and `GIGHIVE_VIDEO_DIR` are not explicitly set in `.env`, Docker Compose falls back to `./_host_audio` and `./_host_video` relative to the bundle directory.

## Initial confusion

The checked-out bundle under:

- `~/gighive/gighive-one-shot-bundle`

had `_host_audio` and `_host_video` owned by `sodo:sodo`, but the actively running exported bundle under `/tmp/gighive-one-shot-bundle` had:

```text
drwxrwxr-x 2 www-data www-data ... _host_audio
drwxrwxr-x 3 www-data www-data ... _host_video
```

The upload script needed to target the runtime `/tmp` instance, not the checked-out source copy under `~/gighive/gighive-one-shot-bundle`.

## Script behavior relevant to this issue

`upload_media_by_hash.py` already supports overriding the destination directories with:

- `--dest-audio`
- `--dest-video`

The defaults are oriented toward a VM-based layout:

- `--dest-audio /home/ubuntu/audio`
- `--dest-video /home/ubuntu/video`

For the one-shot bundle runtime, these had to be overridden to:

- `/tmp/gighive-one-shot-bundle/_host_audio`
- `/tmp/gighive-one-shot-bundle/_host_video`

The working command shape became:

```bash
python3 ~/Downloads/upload_media_by_hash.py \
  --source-root "$HOME/Downloads/20050526/" \
  --ssh-target sodo@192.168.1.235 \
  --dest-audio /tmp/gighive-one-shot-bundle/_host_audio \
  --dest-video /tmp/gighive-one-shot-bundle/_host_video \
  --db-host 192.168.1.235 \
  --db-user appuser \
  --db-name music_db
```

## Failure 1: SSH user and destination path assumptions

The first failures came from using the wrong SSH target and the script's default destination paths.

Example failure:

```text
Failed to ensure remote dir /home/ubuntu/audio: ubuntu@192.168.1.235: Permission denied (publickey,password).
```

### Cause

- the Pop!_OS host user was `sodo`, not `ubuntu`
- the one-shot bundle runtime was not using `/home/ubuntu/audio` or `/home/ubuntu/video`

### Fix

Use:

- `--ssh-target sodo@192.168.1.235`
- `--dest-audio /tmp/gighive-one-shot-bundle/_host_audio`
- `--dest-video /tmp/gighive-one-shot-bundle/_host_video`

## Failure 2: `sodo` could not write into `_host_video`

After correcting the SSH target and destination directories, `rsync` failed with:

```text
rsync: [receiver] mkstemp "/tmp/gighive-one-shot-bundle/_host_video/.<hash>.mp4.<temp>" failed: Permission denied (13)
```

### Diagnostics used

```bash
id sodo
getent group www-data
namei -l /tmp/gighive-one-shot-bundle/_host_video
ls -ld /tmp/gighive-one-shot-bundle/_host_audio /tmp/gighive-one-shot-bundle/_host_video
```

The results showed:

- `sodo` was not yet in the `www-data` group
- `_host_audio` and `_host_video` were owned by `www-data:www-data`
- both directories were mode `775`

### Cause

`_host_audio` and `_host_video` were writable only by:

- owner `www-data`
- group `www-data`

Since `sodo` was not in `www-data`, uploads over SSH could not create files there.

### Fix

Add `sodo` to the `www-data` group on the Pop!_OS host:

```bash
sudo usermod -aG www-data sodo
```

Then start a new login session so the group membership takes effect.

Verification:

```bash
id sodo
```

Expected to include:

- `33(www-data)`

## Failure 3: thumbnail creation still failed

Once media copy succeeded, thumbnail generation failed with:

```text
Could not open file : /tmp/gighive-one-shot-bundle/_host_video/thumbnails/<hash>.png.tmp.png
[vost#0:0/png ...] Error submitting a packet to the muxer: Input/output error
```

### Diagnostics used

```bash
ls -ld /tmp/gighive-one-shot-bundle/_host_video
ls -ld /tmp/gighive-one-shot-bundle/_host_video/thumbnails
namei -l /tmp/gighive-one-shot-bundle/_host_video/thumbnails
getfacl /tmp/gighive-one-shot-bundle/_host_video/thumbnails 2>/dev/null || true
```

The key result was:

```text
drwxr-xr-x 2 www-data www-data ... /tmp/gighive-one-shot-bundle/_host_video/thumbnails
```

And ACL output showed:

```text
user::rwx
group::r-x
```

### Cause

Even though `sodo` was now in `www-data`, the `thumbnails` directory only granted the group read/execute, not write. That prevented `ffmpeg` from creating the temporary PNG file.

### Fix

Grant group write and setgid on the thumbnails directory:

```bash
sudo chown www-data:www-data /tmp/gighive-one-shot-bundle/_host_video/thumbnails
sudo chmod 2775 /tmp/gighive-one-shot-bundle/_host_video/thumbnails
```

This made the directory:

- group-writable
- group-inheriting for new files

## Final successful behavior

After fixing group membership and thumbnail directory permissions, the script behaved correctly.

Representative successful output:

```text
SKIP_COPY_EXISTS	video	20050526/StormPigs20050526_1_PracticeSwing.mp4	/tmp/gighive-one-shot-bundle/_host_video/<hash>.mp4
THUMBNAIL_CREATED	video	20050526/StormPigs20050526_1_PracticeSwing.mp4	/tmp/gighive-one-shot-bundle/_host_video/thumbnails/<hash>.png
DB_REFRESHED	video	20050526/StormPigs20050526_1_PracticeSwing.mp4	/tmp/gighive-one-shot-bundle/_host_video/<hash>.mp4
```

This confirmed that:

- the video already existed or was successfully present
- thumbnail generation succeeded
- the metadata refresh step succeeded

## Recommended operational rules for one-shot bundle uploads

For the one-shot bundle runtime under `/tmp/gighive-one-shot-bundle`:

- upload into the runtime `_host_audio` and `_host_video` directories, not the checked-out source bundle under `~/gighive/gighive-one-shot-bundle`
- use the correct host SSH user for the Pop!_OS machine
- ensure that any host user used for uploads is in the `www-data` group if the runtime media directories are owned by `www-data:www-data`
- ensure `/tmp/gighive-one-shot-bundle/_host_video/thumbnails` is `www-data:www-data` and mode `2775`

## Suggested hardening follow-up

The one-shot bundle export/runtime flow should probably ensure these directories exist with consistent permissions from the start:

- `/tmp/gighive-one-shot-bundle/_host_audio`
- `/tmp/gighive-one-shot-bundle/_host_video`
- `/tmp/gighive-one-shot-bundle/_host_video/thumbnails`

Recommended ownership and mode:

- owner/group: `www-data:www-data`
- mode: `2775`

That would make external uploads and thumbnail generation work more reliably without manual permission repair.
