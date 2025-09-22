# Replace these before running:
# - FILE: path to your video
# - HOST: your server (e.g., https://gighive or https://dev.stormpigs.com)

FILE="/Volumes/scripts/gighive/assets/video/StormPigs20021024_1_fleshmachine.mp4"
FILE="/Volumes/scripts/gighive/assets/video/StormPigs20021024_3_gettingold.mp4"
HOST="https://gighive"    # or "https://dev.stormpigs.com"

curl -ik \
  -u admin:secretadmin \
  -F "file=@${FILE};type=video/mp4" \
  -F "event_date=2025-09-21" \
  -F "org_name=StormPigs" \
  -F "event_type=band" \
  -F "label=Flesh Machine" \
  -F "participants=" \
  -F "keywords=live,stormpigs" \
  -F "location=" \
  -F "rating=" \
  -F "notes=Uploaded via curl test" \
  "${HOST}/api/uploads.php"
