ubuntu@gighive2:~$ docker exec -i apacheWebServer bash -lc 'cat <<JSON | curl -k -u admin:[PSWD] -H "Content-Type: application/json" -d @- https://localhost/import_manifest_add_async.php
{"org_name":"default","event_type":"band","items":[{"file_name":"x.webm","source_relpath":"x.webm","file_type":"video","event_date":"1999-01-01","size_bytes":123,"checksum_sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}]}
JSON'
  % Total    % Received % Xferd  Average Speed   Time    Time     Time  Current
                                 Dload  Upload   Total   Spent    Left  Speed
100   349    0   100  100   249  18148  45190 --:--:-- --:--:-- --:--:-- 69800
{"success":true,"job_id":"20260117-203053-3d98eec3446c","state":"queued","message":"Import started"}ubuntu@gighive2:~$ 
ubuntu@gighive2:~$ 
ubuntu@gighive2:~$ 
ubuntu@gighive2:~$ docker exec -it apacheWebServer bash -lc 'curl -k -u admin:[PSWD] "https://localhost/import_manifest_status.php?job_id=20260117-202926-d92e5dee9bad"'
{"success":true,"job_id":"20260117-202926-d92e5dee9bad","state":"ok","mode":"add","message":"Add-to-database completed successfully.","steps":[{"name":"Upload received","status":"ok","message":"Request received","index":0},{"name":"Validate request","status":"ok","message":"Validated 1 item(s)","index":1},{"name":"Upsert sessions","status":"ok","message":"Sessions ensured: 1","index":2},{"name":"Insert files (dedupe by checksum_sha256)","status":"ok","message":"Inserted: 0, duplicates skipped: 1","index":3},{"name":"Link labels (songs)","status":"ok","message":"Label links created for newly inserted files","index":4}],"result":{"success":true,"job_id":"20260117-202926-d92e5dee9bad","source_job_id":null,"mode":"add","message":"Add-to-database completed successfully.","inserted_count":0,"duplicate_count":1,"duplicates":[{"file_name":"x.webm","source_relpath":"x.webm","checksum_sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}],"steps":[{"name":"Upload received","status":"ok","message":"Request received","index":0},{"name":"Validate request","status":"ok","message":"Validated 1 item(s)","index":1},{"name":"Upsert sessions","status":"ok","message":"Sessions ensured: 1","index":2},{"name":"Insert files (dedupe by checksum_sha256)","status":"ok","message":"Inserted: 0, duplicates skipped: 1","index":3},{"name":"Link labels (songs)","status":"ok","message":"Label links created for newly inserted files","index":4}],"table_counts":[]}}ubuntu@gighive2:~$ 
ubuntu@gighive2:~$ 
ubuntu@gighive2:~$ 
ubuntu@gighive2:~$ 
ubuntu@gighive2:~$ docker exec -it apacheWebServer bash -lc 'ls -ლა /var/www/private/import_jobs/20260117-202926-d92e5dee9bad && tail -n 80 /var/www/private/import_jobs/20260117-202926-d92e5dee9bad/worker.log'
ls: invalid option -- '?'
Try 'ls --help' for more information.
ubuntu@gighive2:~$ docker exec -it apacheWebServer bash -lc 'ls -l /var/www/private/import_jobs/20260117-202926-d92e5dee9bad && tail -n 80 /var/www/private/import_jobs/20260117-202926-d92e5dee9bad/worker.log'
total 16
-rw-r----- 1 www-data www-data  375 Jan 17 20:29 manifest.json
-rw-r----- 1 www-data www-data  124 Jan 17 20:29 meta.json
-rw-r----- 1 www-data www-data 1341 Jan 17 20:29 result.json
-rw-r----- 1 www-data www-data 1066 Jan 17 20:29 status.json
-rw-r--r-- 1 www-data www-data    0 Jan 17 20:29 worker.log
ubuntu@gighive2:~$ 
ubuntu@gighive2:~$ 
ubuntu@gighive2:~$ 
ubuntu@gighive2:~$ docker exec -it apacheWebServer bash -lc 'ls -ld /var/www/private/import_worker.lock || echo "worker lock cleared"'
ls: cannot access '/var/www/private/import_worker.lock': No such file or directory


This is a clean pass — 1–3 look exactly right
What your outputs confirm
Async endpoint worked:
You got a job_id and were able to poll it.
Worker actually ran and finished:
Status shows state:"ok" and you have both status.json and result.json.
Lock guardrail worked and cleaned up properly:
/var/www/private/import_worker.lock is gone afterward, which is what we want.
Why worker.log is empty
That’s expected with the current implementation:

the worker doesn’t print anything on success
and PHP errors/warnings didn’t occur (otherwise you’d see them there)
Why it says “duplicates skipped: 1”
Because you used a fixed checksum of all a’s:

that checksum likely already exists in your files table from an earlier test (or you ran this twice)
so the import correctly treated it as a duplicate and didn’t insert a new row
If you want to see inserted_count: 1, re-run with a new (random) checksum, e.g. any other valid 64-hex string.

Task status
Phase 1 async architecture is validated end-to-end (start → queued/running → ok → result.json, lock cleanup).


#DEBUGGING
root@bf3bcea7c43d:/var/www/html# ls -la /var/www/private/import_jobs/$JOB
total 1164
drwxr-xr-x 2 www-data www-data    4096 Jan 18 03:08 .
drwxr-xr-x 5 www-data www-data    4096 Jan 18 02:54 ..
-rw-r----- 1 www-data www-data 1164575 Jan 18 02:54 manifest.json
-rw-r----- 1 www-data www-data     130 Jan 18 02:54 meta.json
-rw-r----- 1 www-data www-data    7895 Jan 18 03:08 result.json
-rw-r----- 1 www-data www-data    1496 Jan 18 03:08 status.json
-rw-r--r-- 1 www-data www-data       0 Jan 18 02:54 worker.log
root@bf3bcea7c43d:/var/www/html# echo '--- status.json ---'
--- status.json ---
root@bf3bcea7c43d:/var/www/html# cat /var/www/private/import_jobs/$JOB/status.json || true
{
    "success": true,
    "job_id": "20260118-025405-ab7fb27d50e2",
    "state": "ok",
    "message": "Database reload completed successfully.",
    "updated_at": "2026-01-18T03:08:52-05:00",
    "steps": [
        {
            "name": "Upload received",
            "status": "ok",
            "message": "Request received",
            "index": 0
        },
        {
            "name": "Validate request",
            "status": "ok",
            "message": "Validated 3320 item(s)",
            "index": 1
        },
        {
            "name": "Truncate tables",
            "status": "ok",
            "message": "Tables truncated",
            "index": 2
        },
        {
            "name": "Seed genres/styles",
            "status": "ok",
            "message": "Seeded genres/styles",
            "index": 3
        },
        {
            "name": "Upsert sessions",
            "status": "ok",
            "message": "Sessions ensured: 250",
            "index": 4
        },
        {
            "name": "Insert files (dedupe by checksum_sha256)",
            "status": "ok",
            "message": "Inserted: 3270, duplicates skipped: 50",
            "index": 5,
            "progress": {
                "processed": 3320,
                "total": 3320
            }
        },
        {
            "name": "Link labels (songs)",
            "status": "ok",
            "message": "Label links created for newly inserted files",
            "index": 6
        }
    ]
}root@bf3bcea7c43d:/var/www/html# echo '--- worker.log (tail) ---'
--- worker.log (tail) ---
root@bf3bcea7c43d:/var/www/html# tail -n 200 /var/www/private/import_jobs/$JOB/worker.log || true
root@bf3bcea7c43d:/var/www/html# echo '--- meta.json ---'
--- meta.json ---
root@bf3bcea7c43d:/var/www/html# cat /var/www/private/import_jobs/$JOB/meta.json || true
{
    "job_type": "manifest_import",
    "mode": "reload",
    "created_at": "2026-01-18T02:54:05-05:00",
    "item_count": 3320
}root@bf3bcea7c43d:/var/www/html# 
root@bf3bcea7c43d:/var/www/html# 
root@bf3bcea7c43d:/var/www/html# php /var/www/html/import_manifest_worker.php --job_id=20260118-025405-ab7fb27d50e2
Worker lock not present. Refusing to run without lock dir.
root@bf3bcea7c43d:/var/www/html# echo "exit_code=$?"
exit_code=4
root@bf3bcea7c43d:/var/www/html# php -l /home/sodo/scripts/gighive/ansible/roles/docker/files/apache/webroot/admin.php
Could not open input file: /home/sodo/scripts/gighive/ansible/roles/docker/files/apache/webroot/admin.php
root@bf3bcea7c43d:/var/www/html# ll admin.php 
-rw-r--r-- 1 www-data www-data 132928 Jan 17 22:12 admin.php
root@bf3bcea7c43d:/var/www/html# curl -s "http://localhost/import_manifest_status.php?job_id=20260118-025405-ab7fb27d50e2" | head
root@bf3bcea7c43d:/var/www/html# curl -s "http://localhost/import_manifest_status.php?job_id=20260118-025405-ab7fb27d50e2"       
root@bf3bcea7c43d:/var/www/html# curl -sk "https://localhost/import_manifest_status.php?job_id=20260118-025405-ab7fb27d50e2"
{"success":false,"error":"Forbidden","message":"Admin access required"}root@bf3bcea7c43d:/var/www/html# curl -sk "https://localhost/import_manifest_status.php?job_id=20260118-025405-ab7fb27d50e2"
{"success":false,"error":"Forbidden","message":"Admin access required"}root@bf3bcea7c43d:/var/www/html# 
root@bf3bcea7c43d:/var/www/html# 
root@bf3bcea7c43d:/var/www/html# 
root@bf3bcea7c43d:/var/www/html# curl -sk "https://admin:[PSWD]@localhost/import_manifest_status.php?job_id=20260118-025405-ab7fb27d50e2"
{"success":true,"job_id":"20260118-025405-ab7fb27d50e2","state":"ok","mode":"reload","message":"Database reload completed successfully.","steps":[{"name":"Upload received","status":"ok","message":"Request received","index":0},{"name":"Validate request","status":"ok","message":"Validated 3320 item(s)","index":1},{"name":"Truncate tables","status":"ok","message":"Tables truncated","index":2},{"name":"Seed genres\/styles","status":"ok","message":"Seeded genres\/styles","index":3},{"name":"Upsert sessions","status":"ok","message":"Sessions ensured: 250","index":4},{"name":"Insert files (dedupe by checksum_sha256)","status":"ok","message":"Inserted: 3270, duplicates skipped: 50","index":5,"progress":{"processed":3320,"total":3320}},{"name":"Link labels (songs)","status":"ok","message":"Label links created for newly inserted files","index":6}],"result":{"success":true,"job_id":"20260118-025405-ab7fb27d50e2","source_job_id":null,"mode":"reload","message":"Database reload completed successfully.","inserted_count":3270,"duplicate_count":50,"duplicates":[{"file_name":"MVI_5589.MOV","source_relpath":"projects\/christmasConcert\/MVI_5589.MOV","checksum_sha256":"80cc673e5dd6d158670225c94e6268c478ff2729e0b668034e24b05bada45214"},{"file_name":"final.mpg","source_relpath":"projects\/cinelerra\/final.mpg","checksum_sha256":"4982cf40799a90c5bd4120a4a2ff85cac3f0f1137a9e8061566733a78764048b"},{"file_name":"final2.mpg","source_relpath":"projects\/cinelerra\/final2.mpg","checksum_sha256":"b3fadd2b2b2093a91d99ae94667519ef010eb5389d53eb06d5e1d1a71b7b0e27"},{"file_name":"SpaceDog.m2t","source_relpath":"projects\/dogs\/ripley\/spacedog\/SpaceDog.m2t","checksum_sha256":"2c8d3382b02ec5ba1f1a2b74829b5fc400d74ab0758428279f898ecbd2fd83bd"},{"file_name":"SpaceDog.m2t","source_relpath":"projects\/dogs\/SpaceDog\/SpaceDog.m2t","checksum_sha256":"2c8d3382b02ec5ba1f1a2b74829b5fc400d74ab0758428279f898ecbd2fd83bd"},{"file_name":"output2a.mp4","source_relpath":"projects\/freewayTimelapse\/output2a.mp4","checksum_sha256":"bbe1de80389c5edb71d295238260c0a4a6d48bf38d1b51cc8aa827053e140082"},{"file_name":"outputa.mp4","source_relpath":"projects\/freewayTimelapse\/outputa.mp4","checksum_sha256":"e856ae19be8093331f5b56379d2b770c0e4d6a24e408529df6712b98ad023388"},{"file_name":"gighiveSetup.old.mp4","source_relpath":"projects\/gighive\/finals\/old\/gighiveSetup.old.mp4","checksum_sha256":"ba3c55bfabd0592043c17c2c35f3b4cbb2a3739b6286afecd0430995dec36f62"},{"file_name":"greenScreen.mov","source_relpath":"projects\/greenScreen.mov","checksum_sha256":"174117eb5a2cd77002641a952f689f270862dc8210d62134cfd986d2c6c79792"},{"file_name":"MZH00256.MP4","source_relpath":"projects\/houseBuild\/foundationDig\/MZH00256.MP4","checksum_sha256":"e89b2655eddcf92961e08780873e12a4da5ec79f97b17b3832ad7c68e8d142ca"},{"file_name":"MZH00256.ts","source_relpath":"projects\/houseBuild\/foundationDig\/MZH00256.ts","checksum_sha256":"ad1fb925452d2001c53c3de03f5bfbfa4c5a75f3160f4080cf9bca56953c8f33"},{"file_name":"hevcTest4a.mp4","source_relpath":"projects\/morningZen\/bladeRunner\/moonlitsea\/hevcTest\/hevcTest4a.mp4","checksum_sha256":"8ba83b7a277e0eb5d385664ab5c32292eec4fc650addf0fe772cfe313e9b9719"},{"file_name":"moonlitSea1080p.mp4","source_relpath":"projects\/morningZen\/bladeRunner\/moonlitsea\/sharpenBlueSat\/moonlitSea1080p.mp4","checksum_sha256":"aefbe04853ca06bde8f35f7452722426b617661ead5c39fc3b37fb9d06170314"},{"file_name":"moonlitSea4K.mp4","source_relpath":"projects\/morningZen\/bladeRunner\/moonlitsea\/sharpenBlueSat\/moonlitSea4K.mp4","checksum_sha256":"ab0212e34ebeaf7052d610222ab4705fe7dbcd5effa52f2a08d294188691ddf9"},{"file_name":"sharpenBlueSaturateMoonlitSea4K_Profile1kdenlive.mp4","source_relpath":"projects\/morningZen\/bladeRunner\/moonlitsea\/sharpenBlueSat\/sharpenBlueSaturateMoonlitSea4K_Profile1kdenlive.mp4","checksum_sha256":"bf593ee25291610683913c3d995e6020200537c2426a24c4bc41951c009a5099"},{"file_name":"confirm12fpsOriginal.mov","source_relpath":"projects\/reframeRate\/confirm12fpsOriginal.mov","checksum_sha256":"e1138134ab314d248fc4526cd4e82121867111e648dc72552387ab52b59b8e39"},{"file_name":"rudy.mp4","source_relpath":"projects\/rudy\/rudy.mp4","checksum_sha256":"3e82fd06756eb43bebc8a252174478baa223cfc2bdef20791965b5f8ef43a381"},{"file_name":"final.wav","source_relpath":"projects\/sunriseScatman\/final.wav","checksum_sha256":"dbe6ced7592e02ec119a3c093dbca2d67afec915d13825d5637c4f644c3ae01b"},{"file_name":"temp2.mp4","source_relpath":"projects\/sunriseScatman\/temp2.mp4","checksum_sha256":"64337ed51d0c642f7f0273827252c3326d49dfd92397ff5979a4b8e624860840"},{"file_name":"eff1f09b.au","source_relpath":"projects\/tahoe23\/voiceoverTeaser2_data\/eff\/d1f\/eff1f09b.au","checksum_sha256":"b695f7432efa555f3e68d29cd2cfc4c67813fc22dbde6292a78e255efffeabc3"},{"file_name":"eff1f1b4.au","source_relpath":"projects\/tahoe23\/voiceoverTeaser2_data\/eff\/d1f\/eff1f1b4.au","checksum_sha256":"12c57b15938b1ac620ff49a2a641acddf077e477493d2e22526b026156ad2d46"},{"file_name":"eff1f1fc.au","source_relpath":"projects\/tahoe23\/voiceoverTeaser2_data\/eff\/d1f\/eff1f1fc.au","checksum_sha256":"26096874e4bccfdcd7f4e7ed80a502edd76cf09e66f3744db90fad7bfacfb7cb"},{"file_name":"eff1f20c.au","source_relpath":"projects\/tahoe23\/voiceoverTeaser2_data\/eff\/d1f\/eff1f20c.au","checksum_sha256":"27c7c360ce0fe0ec3befba517c585058fa5e7d646bc89a4ab95eb271f1b4e462"},{"file_name":"eff1f261.au","source_relpath":"projects\/tahoe23\/voiceoverTeaser2_data\/eff\/d1f\/eff1f261.au","checksum_sha256":"81b45b971e4ef858a0f8a3aa4e3da1495638f3087ae8ee1ba7d0e1e0dcbc15ea"},{"file_name":"eff1f35d.au","source_relpath":"projects\/tahoe23\/voiceoverTeaser2_data\/eff\/d1f\/eff1f35d.au","checksum_sha256":"b244388a16d4351f852783be26f1d16ae0f0f63e2ab6bfe0d5c9e4dda4616ae9"}],"steps":[{"name":"Upload received","status":"ok","message":"Request received","index":0},{"name":"Validate request","status":"ok","message":"Validated 3320 item(s)","index":1},{"name":"Truncate tables","status":"ok","message":"Tables truncated","index":2},{"name":"Seed genres\/styles","status":"ok","message":"Seeded genres\/styles","index":3},{"name":"Upsert sessions","status":"ok","message":"Sessions ensured: 250","index":4},{"name":"Insert files (dedupe by checksum_sha256)","status":"ok","message":"Inserted: 3270, duplicates skipped: 50","index":5,"progress":{"processed":3320,"total":3320}},{"name":"Link labels (songs)","status":"ok","message":"Label links created for newly inserted files","index":6}],"table_counts":{"sessions":250,"musicians":0,"songs":3069,"files":3270,"session_musicians":0,"session_songs":3167,"song_files":3270}}}root@bf3bcea7c43d:/var/www/html# exit
exit
