*** 
releaseNotes20260204.txt
Changes: Add conditional reboot at bottom of base/tasks/main.yml

Last run (lab: run from lab): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --ask-become-pass" ansible-playbook-lab-20260204.log 

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/base/tasks/main.yml

ToDo: Document upload_media with video (make sure sha2 password to destination is discussed and all the bugaboos) 
ToDo: We should expose any 413 errors directly to the IOS app
ToDo: Fix App Store: not just designed for iPad, what does "not verified" on laptop mean?, "upload to gighive" should change to "Upload, organize, and stream your media."
ToDo: integrate with cddb
ToDo: Remove mysql_native_password=ON
ToDo: Is it worthwhile to have an embed feature?
ToDo: FFmpeg install taking too long at 12min on popos, can we confine ffmpeg install to vm only?
ToDo: fix reload clears sort media library page
ToDo: Check azure build
ToDo: Match cert with cloudflare, name only or something else needed?
ToDo: database table name change to genericize songs 
ToDo: investigate vids that didn't produce thumbnails 
ToDo: rebuild prod baremetal with same ansible scripts as staging
ToDo: Make sure ask-become-pass is run at first part of vbox_provision
ToDo: Investigate user agent: GigHive/1 CFNetwork/3860.300.31 Darwin/25.2.0
ToDo: Is it worthwhile to simplify the audio/video upload vars given docs/audioVideoFullReducedLogic.md?
ToDo: Why is cert creation taking longer now after adding ffmpeg to install?
ToDo: If staging.gighive.app is used as target, pop a message saying, restricted to 100MB
ToDo: create a canonical md versions for the site and convert using composer recommendation
ToDo: cleaning the database won't clear out what has been uploaded to video and audio
ToDo: remove vodcast.xml from webroot for gighive
ToDo: vault index[IM]* php files u/p vault, same for MediaController.php, same for upload.php
ToDo: Integrate Let's Encrypt for future
ToDo: guest user?
ToDo: Should have "backup now" feature

*** 
releaseNotes20260204.txt
Changes: gighive group vars for recent DNS/SSL changes

Last run (dev: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ansible-playbook-gighive2-20260204.log
Last run (lab: run from lab): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ansible-playbook-lab-20260204.log 

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive/gighive.yml

*** 
releaseNotes20260204.txt
Changes: DNS and SSL cert standardizations (gighive_cert_cn: gighive.internal, gighive_cert_dns_sans: [*.gighive.internal, gighive.internal], gighive_fqdn: dev.gighive.internal, gighive_server_aliases: [gighive2.gighive.internal, dev.gighive.app]

Last run (dev: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ansible-playbook-gighive2-20260204.log
Last run (lab: run from lab): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ansible-playbook-lab-20260204.log 

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive/gighive.yml
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/docker/templates/apache2.conf.j2
	modified:   ansible/roles/docker/templates/default-ssl.conf.j2
	modified:   ansible/roles/docker/templates/openssl_san.cnf.j2
	modified:   ansible/roles/post_build_checks/tasks/main.yml
	modified:   docs/cert_internal_no_warnings_guidance.md
	modified:   docs/tus_implementation_guide.md

*** 
releaseNotes20260203.txt
Changes: Pre-ssl cert doc additions and plan

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   docs/cert_internal_no_warnings_guidance.md
	new file:   docs/cert_naming_consistency.md
	deleted:    docs/images/ChatGPT Image Aug 25, 2025, 04_28_44 PM.png
	deleted:    docs/images/ChatGPT Image Aug 25, 2025, 04_28_46 PM.png
	deleted:    docs/images/ChatGPT Image Aug 25, 2025, 04_28_50 PM.png
	new file:   docs/problem_hsts_collision.md

*** 
releaseNotes20260201.txt
Changes: TUS implementation (stash/apply nightmare), TUS uploads: /files endpoint + tusd post-finish hook

Last run (dev: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ansible-playbook-gighive2-20260201.log
Last run (lab: run from lab): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ansible-playbook-lab-20260201.log 

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive/gighive.yml
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	modified:   ansible/roles/docker/files/apache/Dockerfile
	deleted:    ansible/roles/docker/files/apache/webroot/db/singlesRandomPlayer.php.old
	modified:   ansible/roles/docker/files/apache/webroot/db/upload_form.php
	modified:   ansible/roles/docker/files/apache/webroot/db/upload_form_admin.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Controllers/UploadController.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Repositories/FileRepository.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Validation/UploadValidator.php
	modified:   ansible/roles/docker/files/apache/webroot/src/index.php
	new file:   ansible/roles/docker/files/tusd/hooks/post-finish
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/docker/templates/.env.j2
	modified:   ansible/roles/docker/templates/default-ssl.conf.j2
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	modified:   ansible/roles/docker/templates/entrypoint.sh.j2
	modified:   ansible/roles/docker/templates/modsecurity.conf.j2
	modified:   ansible/roles/post_build_checks/tasks/main.yml
	modified:   docs/tus_implementation_guide.md
	modified:   user-prompts.md

*** 
releaseNotes20260131.txt
Changes: Exclude ansible*.log from gighive home

Last run (dev: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --ask-become-pass" ansible-playbook-gighive2-20260130.log
Last run (lab: run from lab): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ansible-playbook-lab-20260131.log 
Last run (staging: run from staging): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ansible-playbook-staging-20260130.log
Last run (prod: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ansible-playbook-prod-20260130.log

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/base/tasks/main.yml

*** 
releaseNotes20260131.txt
Changes: Playbook no longer depends on {{ gighive_home }}/VERSION existing on the guest, provenance signal is timestamped ansible-playbook-*-lastrun-*.log guest. 

Last run (dev: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --ask-become-pass" ansible-playbook-gighive2-20260130.log
Last run (lab: run from lab): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ansible-playbook-lab-20260131.log 
Last run (staging: run from staging): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ansible-playbook-staging-20260130.log
Last run (prod: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ansible-playbook-prod-20260130.log

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/base/tasks/main.yml

*** 
releaseNotes20260131.txt
Changes: Added gighive_marker_author_* fields to all.yml with base/main.yml change, git hook / .gitignore for VERSION file

Last run (dev: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --ask-become-pass" ansible-playbook-gighive2-20260130.log
Last run (lab: run from lab): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --ask-become-pass" ansible-playbook-lab-20260130.log
Last run (staging: run from staging): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ansible-playbook-staging-20260130.log
Last run (prod: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ansible-playbook-prod-20260130.log

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   .gitignore
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/all.yml
	modified:   ansible/roles/base/tasks/main.yml
	renamed:    docs/refactor_gighive_home_and_scripts_dir.md -> docs/refactored_gighive_home_and_scripts_dir.md

*** 
releaseNotes20260130.txt
Changes: Fix for root/gighive in scripts upload (base/tasks/main.yml) and minor changes to remove # in site.yml, .bashrc remove GIGHIVE_HOME, plus README.md change

Last run (dev: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --ask-become-pass" ../ansible-playbook-gighive2-20260130.log
Last run (lab: run from lab): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --ask-become-pass" ../ansible-playbook-lab-20260130.log
Last run (staging: run from staging): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ../ansible-playbook-staging-20260130.log
Last run (prod: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ~/ansible-playbook-prod-20260130.log

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/all.yml
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/base/tasks/main.yml

*** 
releaseNotes20260130.txt
Changes: retire scripts_dir in favor of gighive_home.

Last run (dev: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --ask-become-pass" ../ansible-playbook-gighive2-20260130.log
Last run (lab: run from lab): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --ask-become-pass" ../ansible-playbook-lab-20260130.log
Last run (staging: run from staging): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --ask-become-pass" ../ansible-playbook-staging-20260130.log
Last run (prod: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ~/ansible-playbook-prod-20260130.log

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is ahead of 'origin/master' by 1 commit.
  (use "git push" to publish your local commits)

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/cloud_init/files/network-config
	modified:   ansible/roles/cloud_init/files/user-data

*** 
releaseNotes20260130.txt
Changes: removing controller GIGHIVE_HOME requirement, adding gighive_home, adding VERSION validation + marker

Last run (dev: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ../ansible-playbook-gighive2-20260130.log
Last run (prod: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ~/ansible-playbook-prod-20260130.log
Last run (lab: run from lab): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ../ansible-playbook-lab-20260130.log
Last run (staging: run from staging): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ../ansible-playbook-staging-20260130.log

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/all.yml
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/base/tasks/main.yml
	new file:   docs/refactor_gighive_home_and_scripts_dir.md

*** 
releaseNotes20260124.txt
Changes: Add touch of log in $GIGHIVE_HOME

Last run (lab: run from lab): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ../ansible-playbook-lab-20260130.log
Last run (staging: run from staging): script -q -c "ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ../ansible-playbook-staging-20260130.log
Last run (dev: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ../ansible-playbook-gighive2-20260130.log
Last run (prod: run from dev): script -q -c "ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision" ~/ansible-playbook-prod-20260130.log

*** 
releaseNotes20260124.txt
Changes: Pre-TUS implementation doc updates.

Last run (lab: run from lab): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision


*** 
releaseNotes20260124.txt
Changes: Added %D and %T to apache log for upload duration

Last run (lab: run from lab): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/externalConfigs/logging.conf
	modified:   user-prompts.md

*** 
releaseNotes20260124.txt
Changes: Planning for librarian/assets, musician/session split

Last run (lab: run from lab): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   docs/README.md
	new file:   docs/pr_librarianAsset_musicianSession_changeSet.md
	new file:   docs/questions_for_librarianAsset_musicianSession_decision.md

*** 
releaseNotes20260119.txt
Changes: Add search filters | & ! on db/database.php

Last run (lab: run from lab): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Views/media/list.php

*** 
releaseNotes20260119.txt
Changes: Ethtool update after load test revealed some weakness in network stack, .m2t/.ts mime type change, doc updates

Last run (lab: run from lab): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/cloud_init_disable/tasks/main.yml
	deleted:    ansible/roles/cloud_init_disable/tasks/main.yml.bak2
	modified:   ansible/roles/docker/files/apache/webroot/admin.php
	modified:   ansible/roles/docker/templates/default-ssl.conf.j2
	modified:   docs/admin_data_import_45.md
	new file:   docs/observability_testing_2026019.md
	modified:   docs/uploadMediaByHash.md

*** 

releaseNotes20260118.txt
Changes: Implemented async, recoverable manifest import pipeline for Admin Sections 4/5 with background workers, live polling UI, cancel/replay support, progress/ETA reporting, and no-cache hardening, plus updated documentation.

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	deleted:    ansible/roles/base/tasks/main.yml.beforeOwnershipChangeToWww
	deleted:    ansible/roles/base/tasks/main.yml.beforeReorder
	deleted:    ansible/roles/base/tasks/main.yml.fixUbuntu
	modified:   ansible/roles/docker/files/apache/webroot/admin.php
	new file:   ansible/roles/docker/files/apache/webroot/import_manifest_add_async.php
	new file:   ansible/roles/docker/files/apache/webroot/import_manifest_cancel.php
	modified:   ansible/roles/docker/files/apache/webroot/import_manifest_jobs.php
	new file:   ansible/roles/docker/files/apache/webroot/import_manifest_lib.php
	new file:   ansible/roles/docker/files/apache/webroot/import_manifest_reload_async.php
	modified:   ansible/roles/docker/files/apache/webroot/import_manifest_replay.php
	new file:   ansible/roles/docker/files/apache/webroot/import_manifest_status.php
	new file:   ansible/roles/docker/files/apache/webroot/import_manifest_worker.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php
	modified:   ansible/roles/docker/templates/apache2.conf.j2
	modified:   docs/admin_data_import_45.md
	new file:   docs/new_async_upload_process.sh

*** 
releaseNotes20260116.txt
Changes: Fix any file write activites to utilize timezone of container

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   ansible/roles/docker/files/apache/overlays/gighive/favicon.ico
	modified:   ansible/roles/docker/files/apache/webroot/db/restore_database.php
	modified:   ansible/roles/docker/files/apache/webroot/import_manifest_add.php
	modified:   ansible/roles/docker/files/apache/webroot/import_manifest_jobs.php
	modified:   ansible/roles/docker/files/apache/webroot/import_manifest_reload.php
	modified:   ansible/roles/docker/files/apache/webroot/import_manifest_replay.php
	modified:   ansible/roles/docker/files/apache/webroot/write_resize_request.php

*** 
releaseNotes20260116.txt
Changes: Remove gighive_apache_container variable

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive/gighive.yml
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	modified:   ansible/inventories/group_vars/prod/prod.yml

*** 
releaseNotes20260116.txt
Changes: Standardize references to apacheWebServer as a var in group_vars

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive/gighive.yml
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	modified:   ansible/inventories/group_vars/prod/prod.yml
	renamed:    ansible/roles/docker/files/apache/diag-apache-logs.sh -> ansible/roles/docker/files/apache/webroot/tools/diag-apache-logs.sh
	renamed:    ansible/roles/docker/files/shutdownRemoveApache.sh -> ansible/roles/docker/files/apache/webroot/tools/shutdownRemoveApache.sh
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	modified:   ansible/roles/post_build_checks/tasks/main.yml
	modified:   ansible/roles/security_owasp_crs/handlers/main.yml
	modified:   ansible/roles/security_owasp_crs/tasks/main.yml
	modified:   ansible/roles/security_owasp_crs/tasks/verify.yml
	modified:   ansible/roles/validate_app/tasks/main.yml

*** 
releaseNotes20260116.txt
Changes: Standardize and split references to mysqlServer as two vars in group_vars

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive/gighive.yml
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	modified:   ansible/inventories/group_vars/prod/prod.yml
	renamed:    ansible/roles/docker/files/rebuildContainers.sh -> ansible/roles/docker/files/mysql/dbScripts/rebuildContainers.sh
	renamed:    ansible/roles/docker/files/mysql/shutdownMysqlServer.sh -> ansible/roles/docker/files/mysql/dbScripts/shutdownMysqlServer.sh
	renamed:    ansible/roles/docker/files/mysql/shutdownRemoveMysqlServer.sh -> ansible/roles/docker/files/mysql/dbScripts/shutdownRemoveMysqlServer.sh
	deleted:    ansible/roles/docker/files/testHomePage.sh
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/docker/templates/.env.j2
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	modified:   ansible/roles/post_build_checks/tasks/main.yml
	modified:   ansible/roles/validate_app/tasks/main.yml

*** 
releaseNotes20260116.txt
Changes: UI wiring for recovery (Sections 4 & 5) admin page (previous jobs section)

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/webroot/admin.php
	modified:   ansible/roles/docker/files/apache/webroot/import_manifest_add.php
	new file:   ansible/roles/docker/files/apache/webroot/import_manifest_jobs.php
	modified:   ansible/roles/docker/files/apache/webroot/import_manifest_reload.php
	new file:   ansible/roles/docker/files/apache/webroot/import_manifest_replay.php
	modified:   docs/admin_data_import_45.md

*** 
releaseNotes20260116.txt
Changes: Admin sections 4/5 refactor to reduce code

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

*** 
releaseNotes20260117.txt
Changes: Admin sections 4/5 refactor to reduce code

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/webroot/admin.php
	new file:   docs/admin_data_import_45.md
	new file:   docs/refactor_admin_45_last_steps.md
	modified:   docs/resizeRequestInstructions.md

*** 
releaseNotes20260116.txt
Changes: Fixed .env for apache and created .env.mysql.j2.  Had to adjust gighive_host for testing purposes.

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

*** 
releaseNotes20260116.txt
Changes: Add Section 2B to admin.php for indiv file upload, fix video link on index.md, reduced file size of two images, new arch.png

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/webroot/admin.php
	modified:   docs/index.md

*** 
releaseNotes20260113.txt
Changes: Disable mysql_native_password

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   .gitignore
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/mysql/externalConfigs/z-custommysqld.cnf
	new file:   docs/role_base.md

*** 
releaseNotes20260111.txt
Changes: Upgrade to mysql 8.4 from 8.0

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive/gighive.yml
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	modified:   ansible/roles/docker/files/mysql/externalConfigs/z-custommysqld.cnf
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2

*** 
releaseNotes20260111.txt
Changes: Fix link to setup video in staging

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	modified:   ansible/roles/docker/files/apache/overlays/gighive/index.php

*** 
releaseNotes20260111.txt
Changes: Standardize admin.php messaging

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/webroot/admin.php
	modified:   ansible/roles/post_build_checks/tasks/main.yml
	new file:   docs/refactor_admin_page.md

*** 
releaseNotes20260111.txt
Changes: Restore path traversal fix

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/overlays/gighive/index.php
	modified:   ansible/roles/docker/files/apache/webroot/db/restore_database_status.php

*** 
releaseNotes20260110.txt
Changes: Collapsed images into webroot directory, fixed links

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	deleted:    ansible/roles/docker/files/apache/overlays/gighive/images/databaseErd.png
	deleted:    ansible/roles/docker/files/apache/overlays/gighive/images/uploadutility.png
	modified:   ansible/roles/docker/files/apache/overlays/gighive/index.php
	renamed:    ansible/roles/docker/files/apache/overlays/gighive/images/beelogo.png -> ansible/roles/docker/files/apache/webroot/images/beelogo.png
	renamed:    ansible/roles/docker/files/apache/overlays/gighive/images/beelogoNotTransparent.png -> ansible/roles/docker/files/apache/webroot/images/beelogoNotTransparent.png
	renamed:    ansible/roles/docker/files/apache/overlays/gighive/images/icons/apple-touch-icon.png -> ansible/roles/docker/files/apache/webroot/images/icons/apple-touch-icon.png
	renamed:    ansible/roles/docker/files/apache/overlays/gighive/images/icons/favicon-128.png -> ansible/roles/docker/files/apache/webroot/images/icons/favicon-128.png
	renamed:    ansible/roles/docker/files/apache/overlays/gighive/images/icons/favicon-16.png -> ansible/roles/docker/files/apache/webroot/images/icons/favicon-16.png
	renamed:    ansible/roles/docker/files/apache/overlays/gighive/images/icons/favicon-192.png -> ansible/roles/docker/files/apache/webroot/images/icons/favicon-192.png
	renamed:    ansible/roles/docker/files/apache/overlays/gighive/images/icons/favicon-256.png -> ansible/roles/docker/files/apache/webroot/images/icons/favicon-256.png
	renamed:    ansible/roles/docker/files/apache/overlays/gighive/images/icons/favicon-32.png -> ansible/roles/docker/files/apache/webroot/images/icons/favicon-32.png
	renamed:    ansible/roles/docker/files/apache/overlays/gighive/images/icons/favicon-48.png -> ansible/roles/docker/files/apache/webroot/images/icons/favicon-48.png
	renamed:    ansible/roles/docker/files/apache/overlays/gighive/images/icons/favicon-64.png -> ansible/roles/docker/files/apache/webroot/images/icons/favicon-64.png
	renamed:    ansible/roles/docker/files/apache/overlays/gighive/images/icons/favicon.ico -> ansible/roles/docker/files/apache/webroot/images/icons/favicon.ico
	modified:   ansible/roles/docker/files/apache/webroot/index.php

*** 
releaseNotes20260110.txt
Changes: Added delete media file feature for admins, updated video/thumbnail

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   ansible/roles/docker/files/apache/webroot/db/delete_media_files.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Views/media/list.php
	modified:   ansible/roles/docker/templates/default-ssl.conf.j2
	new file:   docs/autoinstall.md
	new file:   docs/autoinstall.mermaidchart
	new file:   docs/autoinstall_iso_build.sh
	new file:   docs/ubuntuAutoInstallLinuxBootProcess.png

*** 
releaseNotes20260108.txt
Changes: Uploaded instructional video, fixed upload_media script to include upload progress meter

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	deleted:    ansible/roles/cloud_init/tasks/main.yml.beforeLatest
	deleted:    ansible/roles/cloud_init/tasks/main.yml.beforeMacFormat
	deleted:    ansible/roles/cloud_init/tasks/main.yml.newcrappified
	deleted:    ansible/roles/cloud_init/tasks/main.yml.poweroff
	deleted:    ansible/roles/cloud_init/tasks/main.yml.working
	deleted:    ansible/roles/cloud_init/tasks/main.yml.workingMissingUserMetaData
	deleted:    ansible/roles/cloud_init/tasks/main.yml.workingbackup
	modified:   ansible/roles/docker/files/apache/overlays/gighive/index.php
	modified:   ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py
	modified:   docs/index.md
	modified:   docs/uploadMediaByHash.md

*** 
releaseNotes20260108.txt
Changes: Fix staging exception to db and audio and video dirs

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/templates/default-ssl.conf.j2
	modified:   user-prompts.md

*** 
releaseNotes20260108.txt
Changes: Remove root ref, admin timings 

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/webroot/admin.php
	modified:   ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py
	modified:   docs/README.md

*** 
releaseNotes20260107.txt
Changes: Enable VM autostart, manual process only working 

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

# Full builds
Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml (34 minutes) 
Last run (lab: run from lab): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --ask-become-pass (14 minutes)

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive/gighive.yml
	modified:   ansible/playbooks/install_controller.yml
	new file:   ansible/playbooks/vbox_autostart.yml
	new file:   ansible/roles/vbox_vm_autostart/tasks/main.yml
	new file:   docs/autostart_vm_implementation.md

*** 
releaseNotes20260103.txt
Changes: Kvm alternative fix text in cloud_init/tasks/main.yml and list.php header label change

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/cloud_init/tasks/main.yml
	modified:   ansible/roles/docker/files/apache/webroot/src/Views/media/list.php

*** 
releaseNotes20260103.txt
Changes: Readme update

Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   docs/README.md

*** 
releaseNotes20260103.txt
Changes: Fail fast if KVM modules are loaded, deleted outdated files, added alias

Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	deleted:    MediaDatabase.html
	modified:   ansible/roles/cloud_init/tasks/main.yml
	renamed:    uploadMediaForProd.sh -> ansible/roles/docker/files/apache/webroot/tools/uploadMediaByHashExample.sh
	deleted:    docs/guide1Intro.txt
	deleted:    promptScanPlan.txt

*** 
releaseNotes20260103.txt
Changes: Added examples to both home pages

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/overlays/gighive/index.php
	modified:   docs/index.md

*** 
releaseNotes20260103.txt
Changes: Created restore procedure and added internal endpoints doc.

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	modified:   ansible/roles/docker/files/apache/webroot/admin.php
	new file:   ansible/roles/docker/files/apache/webroot/db/restore_database.php
	new file:   ansible/roles/docker/files/apache/webroot/db/restore_database_status.php
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/docker/templates/.env.j2
	modified:   ansible/roles/docker/templates/default-ssl.conf.j2
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	modified:   ansible/roles/mysql_backup/tasks/main.yml
	modified:   ansible/roles/mysql_backup/templates/dbDump.sh.j2
	new file:   docs/images/gighiveMediaLibrary.png
	new file:   docs/internalEndpoints.md

*** 
releaseNotes20260103.txt
Changes: In list.php, linked thumbnail to media URL, return to home page, added explanation. Minor fix to env.j2.  Added backups/roles aliases.

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/docker/files/apache/webroot/src/Views/media/list.php
	modified:   ansible/roles/docker/templates/.env.j2
	modified:   user-prompts.md

*** 
releaseNotes20260102.txt
Changes: Added exception to allow browsers to see the db/database.php page on staging and added links on index pages.

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive/gighive.yml
	modified:   ansible/roles/docker/templates/default-ssl.conf.j2
	modified:   ansible/roles/docker/files/apache/overlays/gighive/index.php
	new file:   docs/images/mediaLibraryCustom.png
	modified:   docs/index.md

*** 
releaseNotes20260101.txt
Changes: Additional log output for upload_media_by_hash.py, once created..copied all SP thumbnails back to source, updated index pages, updated thumbnail column width, and reverse order of checkboxes.

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/docker/files/apache/overlays/gighive/index.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Views/media/list.php
	modified:   ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py
	new file:   docs/images/adminUtilities.png
	modified:   docs/index.md
	renamed:    docs/supportedMediaFormats.md -> docs/mediaFormatsSupported.md

*** 
releaseNotes20251231.txt
Changes: Added --omit-dir-times from video rsync and added thumbnails for default gighive videos, updated resize request instructions

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	modified:   ansible/roles/base/tasks/main.yml
	new file:   assets/video/thumbnails/25823bec0d2950e1253c6d22903cefe5ed4e2fa4cb73da036fba818e67c85f4f.png
	new file:   assets/video/thumbnails/3ed8bbc43ec35bb4662ac8b75843eb89fbd50557eccb3aa960cbc2f6e0601e4d.png
	new file:   assets/video/thumbnails/5a5a63917260e8d1d7b32ac4d5b2998fb48d35d97315874a218f86f02dbc7bbd.png
	new file:   assets/video/thumbnails/b212a654ddf160523373a7da95a0ddbfa752e6dbe57c13b6e220e0211bf71d95.png
	new file:   assets/video/thumbnails/b40e8cc50deea9c90f38df4ae44bfd999db4e2d04815ed1941a381db17c89a31.png
	modified:   docs/resizeRequestInstructions.md

*** 
releaseNotes20251231.txt
Changes: Large change set to add support for thumbnails on db/database.php and default image for audio files (images/audiofile.png), slim down list.php, progress meter on upload_media_by_hash.php, updated resize instructions

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive/gighive.yml
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	modified:   ansible/inventories/group_vars/prod/prod.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/docker/files/apache/webroot/admin.php
	new file:   ansible/roles/docker/files/apache/webroot/images/audiofile.png
	modified:   ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Views/media/list copy.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Views/media/list.php
	modified:   ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py
	new file:   docs/images/diskResizeRequest.png
	modified:   docs/resizeRequestInstructions.md
	modified:   docs/uploadMediaByHash.md
	modified:   user-prompts.md

*** 
releaseNotes20251230.txt
Changes: Minor text changes to overlays/gighive/index.php and admin.php

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/overlays/gighive/index.php
	modified:   ansible/roles/docker/files/apache/webroot/admin.php
	new file:   docs/calculate_durationseconds_hash.md
	new file:   docs/how_are_thumbnails_calculated.md
	new file:   docs/media_file_location_variables.md

*** 
releaseNotes20251230.txt
Changes: Index.md tagline change

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   docs/index.md

*** 
releaseNotes20251227.txt
Changes: Minor fixes to list.php, replaced named default assets for gighive with hashed versions

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/cloud_init/files/network-config
	modified:   ansible/roles/docker/files/apache/webroot/src/Views/media/list.php
	renamed:    assets/audio/20050303_8.mp3 -> assets/audio/007e8780a31bc28ce5a2aa1161748ad7b8864f613697459694d70a3689ee61ca.mp3
	renamed:    assets/audio/20050303_1.mp3 -> assets/audio/1982d30224070906ccee90c54279cb027099c5ab00783b7f4ec5382bb1e42b7a.mp3
	renamed:    assets/audio/20050303_9.mp3 -> assets/audio/1f5509977d26776e5634a23f84ce76ab5363721642b34ebfc138ed1982feea87.mp3
	renamed:    assets/audio/20050303_3.mp3 -> assets/audio/b2fdf8a92ea8358f338921a2b4cdf8afac97c818583430ccc9a059a1cebd69eb.mp3
	renamed:    assets/audio/20050303_5.mp3 -> assets/audio/c6ed20216e7dca7f732340c4207a9458fa8c35d75ddf513c90bf29313a35667f.mp3
	renamed:    assets/audio/20050303_4.mp3 -> assets/audio/c811d7631f5b6a849800222be4b61e8dc531f8215d01e56738281a64acde457a.mp3
	renamed:    assets/audio/20050303_2.mp3 -> assets/audio/d68010b40ac8e07d91762fd5d803dbc35b8bde8eea57c74b62e5cabcbee0380b.mp3
	renamed:    assets/audio/20050303_7.mp3 -> assets/audio/e8acabfe56a4bb5b3f4bdcb2acd7f8055619021b9ae4316bcb10bd6c46e57056.mp3
	renamed:    assets/audio/20050303_6.mp3 -> assets/audio/e9683685ad998f3e1e2c081512df05115e6d6f55881f0e4cd1720206e000d10e.mp3
	renamed:    assets/audio/20050303_10.mp3 -> assets/audio/fc3012499e468cf375fda17e4fece54db6c9b5b9f1a64d9ccb01974f15beb1e8.mp3
	renamed:    assets/video/StormPigs20021024_3_gettingold.mp4 -> assets/video/25823bec0d2950e1253c6d22903cefe5ed4e2fa4cb73da036fba818e67c85f4f.mp4
	new file:   assets/video/32f0133ac6debb9cc42afab3734f400d69851d9020d2db43c9146a399a96e8ac.mp4
	renamed:    assets/video/StormPigs20021024_1_fleshmachine.mp4 -> assets/video/3ed8bbc43ec35bb4662ac8b75843eb89fbd50557eccb3aa960cbc2f6e0601e4d.mp4
	renamed:    assets/video/StormPigs20021024_4_hollowbody.mp4 -> assets/video/5a5a63917260e8d1d7b32ac4d5b2998fb48d35d97315874a218f86f02dbc7bbd.mp4
	renamed:    assets/video/StormPigs20021024_2_fountainofstillness.mp4 -> assets/video/b212a654ddf160523373a7da95a0ddbfa752e6dbe57c13b6e220e0211bf71d95.mp4
	renamed:    assets/video/StormPigs20021024_5_likeadrugaddict.mp4 -> assets/video/b40e8cc50deea9c90f38df4ae44bfd999db4e2d04815ed1941a381db17c89a31.mp4
	modified:   docs/text/timings.txt
	modified:   user-prompts.md

*** 
releaseNotes20251227.txt
Changes: Minor fixes to list.php

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/prod/prod.yml
	new file:   ansible/roles/docker/files/apache/webroot/src/Views/media/list copy.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Views/media/list.php
	deleted:    ansible/roles/docker/files/apache/webroot/views/media/list.php
	new file:   docs/databasePhpColumnRules.txt
	deleted:    test
	new file:   uploadMediaForProd.sh
	modified:   user-prompts.md

*** 
releaseNotes20251226.txt
Plan for today in prep for rolling out csv to hash-first changes
Done: test mysql rebuild for gighive small file list, test app against gighive2, prep gighive group_vars, full rebuild of gighive2, update git, check sonarqube, full rebuild of gighive, test app against gighive, update stormpigs

Changes: Minor fixes for mysql-client and doc

Last run (dev: run from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: run from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: run from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/installprerequisites/vars/main.yml
	modified:   docs/DATABASE_LOAD_METHODS.md
	modified:   user-prompts.md

*** 
releaseNotes20251226.txt
Changes: Massive update to change import, sessioning and mime types/file extensions to support the import of users libraries.  load_and_transform.sql totally changed, migrating from “CSV-driven” to “hash-first”.

Last run (dev: from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   MediaDatabase.html
	modified:   ansible/inventories/group_vars/gighive/gighive.yml
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	new file:   ansible/playbooks/resize_vdi.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/cloud_init/files/network-config
	deleted:    ansible/roles/docker/files/apache/overlays/gighive/src/Controllers/MediaController.php
	deleted:    ansible/roles/docker/files/apache/overlays/gighive/src/Views/media/list.php
	modified:   ansible/roles/docker/files/apache/webroot/admin.php
	modified:   ansible/roles/docker/files/apache/webroot/import_database.php
	new file:   ansible/roles/docker/files/apache/webroot/import_manifest_add.php
	new file:   ansible/roles/docker/files/apache/webroot/import_manifest_reload.php
	new file:   ansible/roles/docker/files/apache/webroot/import_normalized.php
	new file:   ansible/roles/docker/files/apache/webroot/src/Config/MediaTypes.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Repositories/FileRepository.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Validation/UploadValidator.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Views/media/list.php
	new file:   ansible/roles/docker/files/apache/webroot/tools/convert_legacy_database_csv_to_normalized.py
	modified:   ansible/roles/docker/files/apache/webroot/tools/mysqlPrep_full.py
	new file:   ansible/roles/docker/files/apache/webroot/tools/mysqlPrep_normalized.py
	new file:   ansible/roles/docker/files/apache/webroot/tools/run_resize_request.sh
	new file:   ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py
	new file:   ansible/roles/docker/files/apache/webroot/write_resize_request.php
	modified:   ansible/roles/docker/files/mysql/dbScripts/dbCommands.sh
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_full.py
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_sample.py
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvs/conversion_report.txt
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvs/manifest.json
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvs/session_files.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvs/sessions.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvsLarge/conversion_report.txt
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvsLarge/manifest.json
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvsLarge/session_files.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvsLarge/sessions.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvsSmall/conversion_report.txt
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvsSmall/manifest.json
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvsSmall/session_files.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvsSmall/sessions.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_from_legacy_old/conversion_report.txt
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_from_legacy_old/session_files.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_from_legacy_old/sessions.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql
	modified:   ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/files.csv
	renamed:    ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/database_augmented.csv -> ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full_csvmethod/database_augmented.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full_csvmethod/files.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full_csvmethod/musicians.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full_csvmethod/session_musicians.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full_csvmethod/session_songs.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full_csvmethod/sessions.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full_csvmethod/song_files.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full_csvmethod/songs.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/rememberTheseGennedBy1-3B_2-importjobs_3-upload_media_by_hash.txt
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/files.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample_csvmethod/database_augmented.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample_csvmethod/files.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample_csvmethod/musicians.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample_csvmethod/session_musicians.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample_csvmethod/session_songs.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample_csvmethod/sessions.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample_csvmethod/song_files.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample_csvmethod/songs.csv
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/docker/templates/.env.j2
	modified:   ansible/roles/docker/templates/apache2.conf.j2
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	modified:   ansible/roles/docker/templates/entrypoint.sh.j2
	modified:   ansible/roles/docker/templates/php-fpm.conf.j2
	modified:   ansible/roles/docker/templates/www.conf.j2
	modified:   ansible/roles/installprerequisites/vars/main.yml
	modified:   ansible/roles/security_owasp_crs/tasks/verify.yml
	modified:   ansible/roles/validate_app/tasks/main.yml
	modified:   docs/DATABASE_LOAD_METHODS.md
	new file:   docs/DATABASE_LOAD_METHODS_SIMPLIFICATION.md
	new file:   docs/addToDatabaseFeature.md
	new file:   docs/audioVideoFullReducedLogic.md
	new file:   docs/convert_legacy_database.md
	new file:   docs/convert_legacy_database_via_mysql_init.md
	new file:   docs/guide1Intro.txt
	modified:   docs/images/databaseErd.png
	new file:   docs/images/databaseErd.png.bak2
	new file:   docs/images/databaseImportFlows.png
	new file:   docs/images/databaseImportLegacy.png
	new file:   docs/load_and_transform_mysql_initialization.md
	new file:   docs/musiclibrary.txt
	new file:   docs/resizeRequestInstructions.md
	new file:   docs/supportedMediaFormats.md
	new file:   docs/uploadMediaByHash.md
	new file:   promptScanPlan.txt
	new file:   test
	modified:   user-prompts.md

*** 
releaseNotes20251218.txt
Changes: New feature to scan a local folder, grab the media files within and update the database

Last run (dev: from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/webroot/admin.php
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/databaseSmallFourColumns.csv
	modified:   docs/DATABASE_LOAD_METHODS.md

*** 
releaseNotes20251216.txt
Changes: New endpoint for db refresh (full), edit to make sure minimum header fields are there, updated index.md

Last run (staging: from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/webroot/admin.php
	new file:   ansible/roles/docker/files/apache/webroot/import_database.php
	new file:   ansible/roles/docker/files/apache/webroot/tools/mysqlPrep_full.py
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/databaseSmall.csv
	new file:   docs/DATABASE_LOAD_METHODS.md

*** 
releaseNotes20251216.txt
Changes: Add default-mysql-client and python3-pandas to install for web-based db update script, plus ignore python cached files

Last run (staging: from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   .gitignore
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/Dockerfile

*** 
releaseNotes20251216.txt
Changes: Moved admin functions to defaultcodebase, corrected local-infile=1 and location of /etc/mysql/conf.d/z*

Last run (staging: from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	renamed:    ansible/roles/docker/files/apache/overlays/gighive/admin.php -> ansible/roles/docker/files/apache/webroot/admin.php
	renamed:    ansible/roles/docker/files/apache/overlays/gighive/clear_media.php -> ansible/roles/docker/files/apache/webroot/clear_media.php
	modified:   ansible/roles/docker/files/mysql/externalConfigs/z-custommysqld.cnf
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2

*** 
releaseNotes20251215.txt
Changes: Add security for admin.php to default-ssl.conf.j2 

Last run (dev: from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/templates/default-ssl.conf.j2

*** 
releaseNotes20251214.txt
Changes: New databaseErd.png

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	new file:   ansible/roles/docker/files/mysql/dbScripts/selectMediaInfo.sql
	modified:   docs/images/databaseErd.png
	modified:   docs/text/mcDatabaseERD.txt

*** 
releaseNotes20251214.txt
Changes: Add media_info and media_info_tool to FILES table and delimiter fix for files.csv

Last run (dev: from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
- note I have to refresh the repo with the new code to that server before executing
Last run (prod: from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	modified:   ansible/inventories/group_vars/prod/prod.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/docker/files/apache/Dockerfile
	modified:   ansible/roles/docker/files/apache/webroot/src/Repositories/FileRepository.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_full.py
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_sample.py
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/database_augmented.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/files.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/musicians.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/session_musicians.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/session_songs.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/sessions.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/song_files.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/songs.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/select.sql
	modified:   ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql
	modified:   ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/files.csv
	new file:   docs/ADD_MEDIA_INFO_COLUMNS.md

*** 
releaseNotes20251213.txt
Changes: Allow podcasts dir in apache config, changed vodcast to reference new podcasts directory, added contact us to sp page which required a new overflow calculation, flavor_contract doc, new vodcast

*** 
releaseNotes20251213.txt
Changes: Allow podcasts dir in apache config, changed vodcast to reference new podcasts directory, added contact us to sp page which required a new overflow calculation, flavor_contract doc, new vodcast

Last run (prod: from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/base/tasks/main.yml
	deleted:    ansible/roles/docker/files/apache/overlays/gighive/comingsoon.html
	new file:   ansible/roles/docker/files/apache/webroot/images/stormpigsPodcastSplash.png
	modified:   ansible/roles/docker/files/apache/webroot/index.php
	modified:   ansible/roles/docker/files/apache/webroot/vodcast.xml
	modified:   ansible/roles/docker/templates/default-ssl.conf.j2
	new file:   docs/FLAVOR_CONTRACT.md
	modified:   user-prompts.md

*** 
releaseNotes20251213.txt
Changes: resurrect vodcast 

Last run (prod: from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   ansible/roles/docker/files/apache/webroot/images/sp.jpg
	modified:   ansible/roles/docker/files/apache/webroot/vodcast.xml
	modified:   user-prompts.md

*** 
releaseNotes20251213.txt
Changes: new vodcast

Last run (prod: from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision,mysql_backup

*** 
releaseNotes20251211.txt
Changes: extend src/index.php router to support /media-files without altering existing /uploads routes, and new ansible tests for POST and GET /api/media-files endpoints 

Last run (dev: from dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (staging: from staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision
Last run (prod: from dev): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	modified:   ansible/roles/docker/files/apache/webroot/src/index.php
	modified:   ansible/roles/validate_app/tasks/main.yml

*** 
releaseNotes20251210.txt
Changes: Plan for /api/media-files rename as well as non-destructive changes to the apache configuration to support new api name.

Last run (dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision


sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/templates/default-ssl.conf.j2
	modified:   ansible/roles/docker/templates/modsecurity.conf.j2
	deleted:    docs/API_CLEANUP.md
	modified:   docs/API_CURRENT_STATE.md
	modified:   user-prompts.md

*** 
releaseNotes20251210.txt
Changes: API cleanup doc

*** 
releaseNotes20251209.txt
Changes: Added date query params to db/database.php (and db typo fix)

Last run (prod): ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision,mysql_backup
Last run (dev): ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --ask-become-pass
Last run (staging): ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes not staged for commit:
  (use "git add <file>..." to update what will be committed)
  (use "git restore <file>..." to discard changes in working directory)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	modified:   ansible/roles/cloud_init/files/network-config
	modified:   ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Views/media/list.php
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/database.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/database_augmented.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/musicians.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/session_musicians.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/sessions.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/database_augmented.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/musicians.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/session_musicians.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/sessions.csv

*** 
releaseNotes20251208.txt
Changes: Updated SP db and minor group_vars alignment

Last run: ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive2/gighive2.yml
	modified:   ansible/inventories/group_vars/prod/prod.yml
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/database.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/database.csv.bad
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/database.csv.bak2
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/database_augmented.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/files.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/musicians.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/session_musicians.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/session_songs.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/sessions.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/song_files.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/songs.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/database_augmented.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/files.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/musicians.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/session_musicians.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/session_songs.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/sessions.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/song_files.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/songs.csv

*** 
releaseNotes20251208.txt
Changes: Add tests for missing secrets.yml to base/tasks/main.yml

Last run: ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --ask-become-pass
Last run: ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision --ask-become-pass

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   ansible/inventories/group_vars/gighive2/gighive2.yml
	modified:   ansible/inventories/inventory_gighive2.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/cloud_init/files/meta-data
	modified:   ansible/roles/cloud_init/files/network-config
	modified:   ansible/roles/cloud_init/files/user-data
	modified:   user-prompts.md

*** 
releaseNotes20251208.txt
Changes: Removed hardcoded pw from dbscripts shell scripts

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/mysql/dbScripts/dbCommands.sh
	modified:   ansible/roles/docker/files/mysql/dbScripts/execute.sh
	modified:   ansible/roles/docker/files/mysql/dbScripts/reloadMyDatabase.sh
	modified:   ansible/roles/docker/files/mysql/dbScripts/verifyTables.sh

*** 
releaseNotes20251208.txt
Changes: Remove secrets.yml from git, add explanation about creating own in setup doc

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   .gitignore
	modified:   CHANGELOG.md
	new file:   ansible/inventories/group_vars/gighive/secrets.example.yml
	modified:   docs/README.md
 
*** 
releaseNotes20251208.txt
Changes: App terms of service, remove MIT license
 
sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   docs/APP_TERMS_OF_SERVICE.md
	modified:   docs/index.md

*** 
releaseNotes20251202.txt
Changes: Moved inventory_baremetal.yml to inventory_prod.yml and copied performance doc to docs

Last run: ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes not staged for commit:
  (use "git add/rm <file>..." to update what will be committed)
  (use "git restore <file>..." to discard changes in working directory)
	deleted:    ansible/inventories/inventory_baremetal.yml

Untracked files:
  (use "git add <file>..." to include in what will be committed)
	ansible/inventories/inventory_prod.yml
	docs/VIDEO_PERFORMANCE_DEBUG.md

*** 
releaseNotes20251201.txt
Changes: CORS headers for Content-Range, Content-Length fix and rename vm_name/hostname vars in prod.yml

From prod
Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml --skip-tags vbox_provision,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/prod/prod.yml
	modified:   user-prompts.md

*** 
releaseNotes20251201.txt

Changes: CORS headers for Content-Range, Content-Length fix and rename vm_name/hostname vars in prod.yml

From staging
Last run: gmk@staging:~/gighive$ ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml  --skip-tags vbox_provision,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/templates/apache2.conf.j2
	new file:   docs/RANGE_REQUEST_FIX.md
	modified:   docs/README.md
	modified:   user-prompts.md

*** 
releaseNotes20251130.txt
Changes: Renamed lab to staging, deprecated old gighive on popos, stood up on new staging (old lab box) and tested, changed references from dev. to staging.

Last run: ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --ask-become-pass

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   docs/DOCKER_IMAGE_BUILD_CHANGE.md
	modified:   docs/FOUR_PAGE_REARCHITECTURE.md
	modified:   docs/index.md
	modified:   docs/protected/CLOUDFLARE_UPLOAD_LIMIT.md
	modified:   user-prompts.md

*** 
releaseNotes20251130.txt
Changes: Updated ANSIBLE_FILE_INTERACTION.md and png, linked CONTENT_RANGE_CLOUDFLARE.md
 
sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   docs/ANSIBLE_FILE_INTERACTION.md
	new file:   docs/CONTENT_RANGE_CLOUDFLARE.md
	modified:   docs/index.md
	modified:   user-prompts.md

*** 
releaseNotes20251130.txt
Changes: Change ubuntu to prod, fix broken content-range for non-range requests bug
 
Tested on gighive
Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --tags set_targets,base,docker,security_basic_auth,security_owasp_crs,post_build_checks,validate_app
Tested on prod
sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml --skip-tags vbox_provision,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	renamed:    ansible/inventories/group_vars/ubuntu/ubuntu.yml -> ansible/inventories/group_vars/prod/prod.yml
	renamed:    ansible/inventories/group_vars/ubuntu/secrets.yml -> ansible/inventories/group_vars/prod/secrets.yml
	modified:   ansible/inventories/inventory_baremetal.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/docker/templates/apache2.conf.j2
	modified:   docs/ANSIBLE_FILE_INTERACTION.md
	modified:   docs/README.md
	new file:   docs/images/ansibleFileInteraction.png
	modified:   user-prompts.md

*** 
releaseNotes20251130.txt
Changes: None, testing prod.
 
Tested in prod
Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml --skip-tags vbox_provision,mysql_backup

*** 
releaseNotes20251129.txt
Changes: db/database.php health check (status only) and validation

Tested on gighive
Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --tags set_targets,base,docker,security_basic_auth,security_owasp_crs,post_build_checks,validate_app

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/inventory_bootstrap.yml
	new file:   ansible/roles/docker/files/apache/webroot/db/health.php
	modified:   ansible/roles/docker/templates/default-ssl.conf.j2
	modified:   ansible/roles/validate_app/tasks/main.yml
	new file:   docs/APACHE_DIRECTIVE_MATCHING_ORDER.md
	new file:   docs/database-health-check-testing.md
	modified:   user-prompts.md

*** 
releaseNotes20251129.txt
Changes: Vendor autoload location fix to clear_media.php, working, add cleared db button.  Also changed name of page to admin.php"

Tested on lab machine 
Last run: gmk@lab:~/gighive$ ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	renamed:    ansible/roles/docker/files/apache/overlays/gighive/changethepasswords.php -> ansible/roles/docker/files/apache/overlays/gighive/admin.php
	modified:   ansible/roles/docker/files/apache/overlays/gighive/index.php
	modified:   docs/ADMIN_CLEAR_MEDIA.md
	modified:   user-prompts.md

*** 
releaseNotes20251128.txt
Changes: Added notification for when passwords changed

Tested on gighive
Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2

*** 
releaseNotes20251128.txt
Changes: test htpasswd changes on gighive

Tested on gighive
Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2

*** 
releaseNotes20251128.txt
Changes: htpasswd change until db handles

Tested on lab
Last run: ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/inventory_virtualbox.yml
	modified:   ansible/roles/docker/files/apache/webroot/changethepasswords.php
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/post_build_checks/tasks/main.yml
	modified:   ansible/roles/security_basic_auth/tasks/main.yml
	new file:   docs/HTPASSWD_CHANGES.md
	modified:   user-prompts.md

*** 
releaseNotes20251128.txt
Changes: Moved location of admin file 

Last run: gmk@lab:~/gighive$ ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --skip-tags vbox_provision,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	renamed:    ansible/roles/docker/files/apache/overlays/gighive/changethepasswords.php -> ansible/roles/docker/files/apache/webroot/changethepasswords.php

*** 
releaseNotes20251127.txt
Changes: Jibed dev secrets removal with prod and new inventory structure, new doc on mixing hostvm and docker versions

Last run: ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   ansible/inventories/group_vars/ubuntu/secrets.yml
	renamed:    ansible/inventories/group_vars/prod.yml -> ansible/inventories/group_vars/ubuntu/ubuntu.yml
	new file:   docs/MIXING_HOSTVM_DOCKER_VERSIONS.md
	modified:   docs/index.md
	modified:   user-prompts.md

*** 
releaseNotes20251127.txt
Changes: Update azure terraform/main.tf for noble build, fix timesyncd cache update

Last run: ansible-playbook -i ansible/inventories/inventory_azure.yml   ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/inventory_azure.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   docs/README.md
	modified:   terraform/main.tf
	new file:   terraform/main.tf.beforeNobleUpdate
	modified:   terraform/tfplan

*** 
releaseNotes20251125.txt
Changes: More stop using deprecated ansible_* magic facts and readme

Last run: gmk@lab:/home$ ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --ask-become-pass 

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/cloud_init_disable/tasks/main.yml
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/varscope/tasks/main.yml
	modified:   docs/README.md
	modified:   user-prompts.md

*** 
releaseNotes20251125.txt
Changes: Updated Ansible config and roles to stop using deprecated ansible_* magic facts and the old YAML callback, switched to ansible_facts[...] and modern callback settings so playbooks run cleanly

Last run: gmk@lab:/home$ ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --ask-become-pass 

*** 
releaseNotes20251119.txt
Changes: Moved user passwords out of group_vars/gighive/gighive.yml into secrets.yml 

Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --ask-become-pass --skip-tags blobfuse2

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive/gighive.yml
	modified:   ansible/inventories/group_vars/gighive/secrets.yml
	deleted:    ansible/inventories/group_vars/ubuntu.yml
	modified:   docs/README.md

*** 
releaseNotes20251118.txt
Changes: Moved MySQL passwords out of scripts and templates, Introduced group_vars/gighive/secrets.yml, routed secrets through a rendered .env file on the VM, standardized Ansible group_vars into group_vars/gighive/

Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --ask-become-pass --skip-tags blobfuse2

# What was done
Moved MySQL passwords out of scripts and templates
Introduced group_vars/gighive/secrets.yml to hold mysql_root_password and mysql_appuser_password (plain now, Vault-ready later).
Routed secrets through a rendered .env file on the VM
Standardized Ansible group_vars into group_vars/gighive/, gighive.yml (main config) and secrets.yml (MySQL secrets).

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	renamed:    ansible/inventories/group_vars/gighive.yml -> ansible/inventories/group_vars/gighive/gighive.yml
	new file:   ansible/inventories/group_vars/gighive/secrets.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/docker/files/mysql/dbScripts/dbCommands.sh
	modified:   ansible/roles/docker/templates/.env.j2
	modified:   docs/ANSIBLE_FILE_INTERACTION.md

*** 
releaseNotes20251115.txt
Changes: Upgrade to Ubuntu 24.04: Had to removed docker-compose v1 for 22.04 compat, removed php-fpm.conf from dockerfile in favor of bind mount, changed Dockerfile ubuntu version to 24.04, variablized PHP version to 8.3 in group vars, www.conf.j2/php-fpm.conf.j2 are new jinja2 templates, edited group_vars for ubuntu version (Dockerfile manual)

Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --ask-become-pass --skip-tags blobfuse2
Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --ask-become-pass --skip-tags blobfuse2

*** 
releaseNotes20251110.txt
Changes: Created and tested second gighive config for testing (site.yml/3 others), Fixed relative path in ansible.cfg, doc'd ansible core files

Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --ask-become-pass --skip-tags blobfuse2

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible.cfg
	modified:   ansible/inventories/group_vars/gighive.yml
	new file:   ansible/inventories/group_vars/gighive2.yml
	new file:   ansible/inventories/group_vars/prod.yml
	new file:   ansible/inventories/inventory_gighive2.yml
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/cloud_init/files/meta-data
	modified:   ansible/roles/cloud_init/files/network-config
	modified:   ansible/roles/cloud_init/files/user-data
	new file:   docs/ANSIBLE_FILE_INTERACTION.md
	modified:   user-prompts.md

*** 
releaseNotes20251109.txt
Changes: Plan for JWT migration, updated SP db

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/ubuntu.yml
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/database.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/editDatabaseThenRunShell-Python.txt
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/database_augmented.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/files.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/musicians.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/session_musicians.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/session_songs.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/sessions.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/song_files.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/songs.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/database_augmented.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/sessions.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/songs.csv
	new file:   docs/codingChanges/JWT_AUTH_MIGRATION_MAPPING.md
	modified:   user-prompts.md

*** 
releaseNotes20251108.txt
Changes: Updated SP db 

Last run: ansible-playbook   -i ansible/inventories/inventory_baremetal.yml   ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2,mysql_backup

*** 
releaseNotes20251108.txt
Changes: Update Server header and description of pages in app

Last run: ansible-playbook   -i ansible/inventories/inventory_virtualbox.yml   ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2,mysql_backup
Last run: ansible-playbook   -i ansible/inventories/inventory_baremetal.yml   ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/externalConfigs/apache2.conf
	modified:   ansible/roles/docker/files/apache/overlays/gighive/index.php
	modified:   docs/index.md

*** 
releaseNotes20251107.txt
Changes: New version of content policy

*** 
releaseNotes20251107.txt
Changes: Eliminated duplicate openapi.yml and created swagger documentation at docs/api-docs.html

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,security_owasp_crs,security_basic_auth,post_build_checks
Last run: ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,security_owasp_crs,security_basic_auth,post_build_checks

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   VERSION
	modified:   ansible/inventories/group_vars/gighive.yml
	new file:   ansible/roles/docker/files/apache/webroot/docs/api-docs.html
	modified:   ansible/roles/docker/files/apache/webroot/docs/openapi.yaml
	deleted:    ansible/roles/docker/files/api/openapi.yaml
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   docs/index.md
	modified:   user-prompts.md

*** 
releaseNotes20251107.txt
Changes: Migration of /api/uploads.php to clean MVC architecture 
Version Update to 1.0.1

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,security_owasp_crs,security_basic_auth,post_build_checks
Last run: ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,security_owasp_crs,security_basic_auth,post_build_checks

	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/roles/docker/files/apache/overlays/gighive/index.php
	modified:   ansible/roles/docker/files/apache/overlays/gighive/src/Views/media/list.php
	modified:   ansible/roles/docker/files/apache/webroot/api/uploads.php
	modified:   ansible/roles/docker/files/apache/webroot/db/upload_form.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Views/media/list.php
	modified:   ansible/roles/docker/templates/default-ssl.conf.j2
	modified:   docs/index.md
	modified:   user-prompts.md
	ansible/roles/docker/files/apache/webroot/src/index.php
	ansible/roles/docker/files/apache/webroot/src/test.php
	docs/API_CLEANUP.md
	docs/POTENTIAL_API_CLEANUP_IF_DESIRED.md
	docs/favicon.ico
	docs/images/requestFlowBasic.png
	docs/images/requestFlowFull.png

*** 
releaseNotes20251027.txt
Changes: First bootstrap migration phase done

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is ahead of 'origin/master' by 1 commit.
  (use "git push" to publish your local commits)

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   ansible/inventories/inventory_vbox_new_bootstrap.yml
	new file:   ansible/inventories/inventory_vbox_new_bootstrap.yml.localhost
	new file:   ansible/playbooks/install_controller.yml
	new file:   ansible/playbooks/verify_controller.yml
	new file:   ansible/roles/installprerequisites/defaults/main.yml
	new file:   ansible/roles/installprerequisites/tasks/azure.yml
	new file:   ansible/roles/installprerequisites/tasks/ensure_collections.yml
	new file:   ansible/roles/installprerequisites/tasks/main.yml
	new file:   ansible/roles/installprerequisites/tasks/python_venv.yml
	new file:   ansible/roles/installprerequisites/tasks/terraform.yml
	new file:   ansible/roles/installprerequisites/tasks/verify.yml
	new file:   ansible/roles/installprerequisites/tasks/virtualbox.yml
	new file:   ansible/roles/installprerequisites/vars/main.yml
	new file:   docs/BOOTSTRAP_PHASE1.md
	modified:   docs/README.md
	modified:   user-prompts.md

*** 
releaseNotes20251028.txt
Changes: tagged pre-bootstrap

sodo@pop-os:~/scripts/gighive$ grep 'git tag' ~/.bash_history 
git tag pre-bootstrap-rearchitecture

*** 
releaseNotes20251027.txt
Changes: FOUR_PAGE_REARCHITECTURE.md and index.md update

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   docs/FOUR_PAGE_REARCHITECTURE.md
	modified:   docs/index.md
	modified:   user-prompts.md

*** 
releaseNotes20251025.txt
Changes: fixed rebuild_mysql_data: true logic because it uses directory name where docker-compose lives as volume name files_mysql_data 

ansible-playbook   -i ansible/inventories/inventory_virtualbox.yml   ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2,mysql_backup -e "rebuild_mysql_data=true"

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   docs/DOCKER_COMPOSE_BEHAVIOR.md
	modified:   user-prompts.md

*** 
releaseNotes20251025.txt
Changes: added rebuild_mysql var to group_vars only and reference in docker/tasks/main.yml, and updated docs

ansible-playbook   -i ansible/inventories/inventory_virtualbox.yml   ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2,mysql_backup 

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/inventories/group_vars/ubuntu.yml
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   docs/DOCKER_COMPOSE_BEHAVIOR.md
	modified:   user-prompts.md

*** 
releaseNotes20251025.txt
Changes: Add json output to MediaController for API, make docker-compose behavior rebuild each time, document the change in /docs

ansible-playbook   -i ansible/inventories/inventory_virtualbox.yml   ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2,mysql_backup 

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   DATABASE_VIEWER_IMPLEMENTATION_PLAN.md
	deleted:    ansible/roles/docker/files/apache/overlays/gighive/SECURITY.html
	modified:   ansible/roles/docker/files/apache/overlays/gighive/src/Controllers/MediaController.php
	modified:   ansible/roles/docker/files/apache/webroot/db/database.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php
	deleted:    ansible/roles/docker/files/rebuildApacheOnly.sh
	renamed:    ansible/roles/docker/files/rebuildForDb.sh -> ansible/roles/docker/files/rebuildContainers.sh
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	new file:   docs/DOCKER_COMPOSE_BEHAVIOR.md
	new file:   docs/DOCKER_IMAGE_BUILD_CHANGE.md
	modified:   docs/index.md
	modified:   user-prompts.md

*** 
releaseNotes20251012.txt
Changes: Fixed 20060831 duplicate songs bug, documented database import process 

ansible-playbook   -i ansible/inventories/inventory_virtualbox.yml   ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/__pycache__/mysqlPrep_full.cpython-310.pyc
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/__pycache__/mysqlPrep_sample.cpython-310.pyc
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_full.py
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_full.py.backup
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_sample.py
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_sample.py.backup
	renamed:    ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/cleaned_stormpigs_database_augmented.csv -> ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/database_augmented.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/files.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/musicians.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/session_musicians.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/session_songs.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/sessions.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/song_files.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/songs.csv
	deleted:    ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/cleaned_stormpigs_database_augmented.csv
	renamed:    ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/cleaned_stormpigs_database_augmented.csv -> ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/database_augmented.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/files.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/session_songs.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/song_files.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/songs.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/database_augmented.csv
	new file:   docs/database-import-process.md

*** 
releaseNotes20251012.txt
Changes: Don't overwrite dbScripts/backups and make database name generic

ansible-playbook   -i ansible/inventories/inventory_virtualbox.yml   ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2,mysql_backup

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/base/tasks/main.yml
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/database.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_full.py
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_sample.py

*** 
releaseNotes20251012.txt
Changes: Index updates, added 20050721 

ansible-playbook   -i ansible/inventories/inventory_virtualbox.yml   ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2,mysql_backup

*** 
releaseNotes20251008.txt
Changes: Created hamburger dropdown for the library

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   .gitignore
	modified:   CHANGELOG.md
	modified:   docs/index.md
	modified:   docs/tusimplementationweek1.md
	modified:   user-prompts.md

*** 
releaseNotes20251008.txt
Changes: Increased SecRequestBodyNoFilesLimit 5368709120

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/templates/modsecurity.conf.j2

*** 
releaseNotes20251001.txt
Changes: Added email contact to bottom of readme and index.

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   docs/COMMON.md
	modified:   docs/README.md
	modified:   docs/index.md

*** 
releaseNotes20250930.txt
Changes: linked index.md to the licensing

*** 
releaseNotes20250930.txt
Changes: moved index.html to index.md.  Created COMMON.md for commonalities between index/README. Added licensing.

*** 
releaseNotes20250928.txt
Changes: README.md changes

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   docs/README.md

*** 
releaseNotes20250928.txt
Changes: Last changes before i delete ios app

sodo@pop-os:~/scripts/gighive$ git commit -m "Changes: Last changes before i delete ios app"
[master 659ee69] Changes: Last changes before i delete ios app
 3 files changed, 14 insertions(+), 12 deletions(-)
sodo@pop-os:~/scripts/gighive$ git push
Enumerating objects: 17, done.
Counting objects: 100% (17/17), done.
Delta compression using up to 16 threads
Compressing objects: 100% (8/8), done.
Writing objects: 100% (9/9), 1.04 KiB | 1.04 MiB/s, done.
Total 9 (delta 6), reused 0 (delta 0), pack-reused 0
remote: Resolving deltas: 100% (6/6), completed with 6 local objects.
To github.com:gighive/gighive
   4cad31d..659ee69  master -> master

*** 
releaseNotes20250928.txt
Changes: Changed flavor to defaultcodebase, removed extraneous docs

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,security_owasp_crs,security_basic_auth,post_build_checks
Last run: ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,security_owasp_crs,security_basic_auth,post_build_checks

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/ubuntu.yml
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	deleted:    docs/20250926currentauth.md
	renamed:    docs/20250926overallchanges.md -> docs/20250926uploaderConfDetail.md
	renamed:    docs/testApacheHeaders.md -> docs/chunkedHeaderTest.md
	renamed:    docs/20250926changepasswords.md -> docs/featureChangedPasswordsPage.md
	new file:   docs/featureFixShareExtension.md
	renamed:    docs/tusserverimplementation.md -> docs/futureTusServerImplementation.md
	deleted:    docs/tusclientchunkimplementation.md
	modified:   user-prompts.md

*** 
releaseNotes20250928.txt
Changes: Updated .gitignore for iPhone app and moved txt docs around

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is ahead of 'origin/master' by 1 commit.
  (use "git push" to publish your local commits)

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   .gitignore
	modified:   CHANGELOG.md
	renamed:    docs/apiStructure.txt -> docs/text/apiStructure.txt
	renamed:    docs/build.txt -> docs/text/build.txt
	renamed:    docs/commonissue1.txt -> docs/text/commonissue1.txt
	renamed:    docs/commonissue2.txt -> docs/text/commonissue2.txt
	renamed:    docs/composerRebuild.txt -> docs/text/composerRebuild.txt
	renamed:    docs/mcDatabaseERD.txt -> docs/text/mcDatabaseERD.txt
	renamed:    docs/mcMvcModel1.txt -> docs/text/mcMvcModel1.txt
	renamed:    docs/mcMvcModel2.txt -> docs/text/mcMvcModel2.txt
	renamed:    docs/mvcModel.txt -> docs/text/mvcModel.txt
	renamed:    docs/timings.txt -> docs/text/timings.txt

*** 
releaseNotes20250927.txt
Changes: Created reduced database page for gighive, files changed in overlay

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	new file:   ansible/roles/docker/files/apache/overlays/gighive/src/Controllers/MediaController.php
	modified:   ansible/roles/docker/files/apache/overlays/gighive/src/Views/media/list.php
	new file:   docs/featureGighiveDbOverlay.md
	modified:   user-prompts.md
 
*** 
releaseNotes20250927.txt
Changes: iphone App fixed upload messaging

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	new file:   ansible/roles/docker/files/apache/webroot/db/databaseFullView.php
	modified:   ios/GigHive/GigHive.xcodeproj/xcshareddata/xcschemes/GigHive.xcscheme
	modified:   ios/GigHive/Sources/App/._UploadFormView.swift
	modified:   ios/GigHive/Sources/App/UploadView.swift
	new file:   ios/GigHive/Sources/App/UploadView.swift.bad
	new file:   ios/GigHive/Sources/App/UploadView.swift.workingmoredebug
	modified:   user-prompts.md

*** 
releaseNotes20250927.txt
Changes: iphone App gui and loading media changes

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   docs/tusimplementation.md
	new file:   docs/tusimplementationweek1.md
	new file:   docs/tusserverimplementation.md
	modified:   ios/GigHive/Configs/AppInfo.plist
	modified:   ios/GigHive/GigHive.xcodeproj/project.pbxproj
	deleted:    ios/GigHive/GigHive.xcodeproj/project.xcworkspace/._contents.xcworkspacedata
	modified:   ios/GigHive/GigHive.xcodeproj/xcshareddata/xcschemes/GigHive.xcscheme
	modified:   ios/GigHive/GigHive.xcodeproj/xcshareddata/xcschemes/GigHiveShare.xcscheme
	modified:   ios/GigHive/Sources/App/GigHiveApp.swift
	new file:   ios/GigHive/Sources/App/NetworkProgressUploadClient.swift
	new file:   ios/GigHive/Sources/App/TUSUploadClient_Clean.swift
	modified:   ios/GigHive/Sources/App/UploadClient.swift
	modified:   ios/GigHive/Sources/App/UploadView.swift
	modified:   ios/GigHive/project.yml
	modified:   user-prompts.md

*** 
releaseNotes20250927.txt
Changes: Tus client implementation update

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   docs/tusclientchunkimplementation.md
	modified:   user-prompts.md

*** 
releaseNotes20250927.txt
Changes: Increase memory_limit for uploads from 32MB to 512MB, chunked upload configuration doc

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   .gitignore
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/externalConfigs/www.conf
	new file:   docs/chunkedfileconfiguration.md
	modified:   user-prompts.md

*** 
releaseNotes20250927.txt
Changes: Tus implementation planning, phpinfo.php for testing

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,security_owasp_crs,security_basic_auth,post_build_checks

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is ahead of 'origin/master' by 1 commit.
  (use "git push" to publish your local commits)

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   .gitignore
	modified:   CHANGELOG.md
	new file:   ansible/roles/docker/files/apache/webroot/phpinfo.php
	new file:   docs/testApacheHeaders.md
	new file:   docs/tusclientchunkimplementation.md
	new file:   docs/tusimplementation.md
	new file:   ios/GigHive/Sources/App/Info.plist

*** 
releaseNotes20250926.txt
Changes: Iphone App cleanup (appears as git log "Stop tracking AppleDouble and Xcode user-state files") 

*** 
releaseNotes20250926.txt
Changes: Documentation rename plus changethepasswords.php to add the uploader user

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/overlays/gighive/changethepasswords.php
	new file:   docs/20250926changepasswords.md
	renamed:    docs/currentauth.md -> docs/20250926currentauth.md
	renamed:    docs/changes-2025-09-26.md -> docs/20250926overallchanges.md
	renamed:    docs/securityauthchangesforuploader.md -> docs/20250926securityauthchanges.md
	renamed:    docs/uploader_minimal_changes.md -> docs/20250926uploaderchanges.md

*** 
releaseNotes20250926.txt
Changes: Upload confirmation HTML with database link, per-row anchors in database list and header anchor rename

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes not staged for commit:
  (use "git add <file>..." to update what will be committed)
  (use "git restore <file>..." to discard changes in working directory)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/webroot/api/uploads.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Views/media/list.php

*** 
releaseNotes20250926.txt
Changes: Secondary set of changes to add uploader user to gighive.yml and security_auth_basic/tasks/main.yml

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,security_owasp_crs,security_basic_auth,post_build_checks

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/roles/security_basic_auth/tasks/main.yml

*** 
releaseNotes20250926.txt
Changes: Minimal set of changes to consolidate uploader user into default-ssl.conf.j2

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes not staged for commit:
  (use "git add <file>..." to update what will be committed)
  (use "git restore <file>..." to discard changes in working directory)
	modified:   ansible/roles/docker/templates/apache2.conf.j2
	modified:   ansible/roles/docker/templates/default-ssl.conf.j2
	modified:   ansible/roles/post_build_checks/tasks/main.yml
	modified:   ansible/roles/security_basic_auth/tasks/main.yml

*** 
releaseNotes20250926.txt
Changes: Add currentauth explanation

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	renamed:    currentauth.md -> docs/currentauth.md

*** 
releaseNotes20250926.txt
Changes: Prep for auth changes, uploader_minimal_changes.md

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   currentauth.md
	new file:   docs/uploader_minimal_changes.md

*** 
releaseNotes20250925.txt
Changes: Automated github CNAME change to add gighive.app

Had to rebase

sodo@pop-os:~/scripts/gighive$ git push origin master
Enumerating objects: 12, done.
Counting objects: 100% (12/12), done.
Delta compression using up to 16 threads
Compressing objects: 100% (9/9), done.
Writing objects: 100% (9/9), 4.12 KiB | 4.12 MiB/s, done.
Total 9 (delta 5), reused 0 (delta 0), pack-reused 0
remote: Resolving deltas: 100% (5/5), completed with 3 local objects.
To github.com:gighive/gighive
   b61b4d1..60ec0a8  master -> master
sodo@pop-os:~/scripts/gighive$ git log -5
commit 60ec0a8e2f78923da11cfbc8dbbc3ede63bc2d72 (HEAD -> master, origin/master, origin/HEAD)
Author: Scott Frase <frases@hotmail.com>
Date:   Fri Sep 26 08:42:43 2025 -0400

    Update CHANGELOG

commit 0f8ee7c32cb5dd538afe48999a7844b611303e52
Author: Scott Frase <frases@hotmail.com>
Date:   Fri Sep 26 08:32:54 2025 -0400

    Changes: Prep for auth changes, uploader_minimal_changes.md

commit b61b4d106137c384fe78ef6f0773090596229a32
Author: gighive <frases@hotmail.com>
Date:   Thu Sep 25 08:41:43 2025 -0400

    Create CNAME
*** 
releaseNotes20250924.txt
Changes: Fixed randomizer, made label changes to upload_form, created apache log diag script.

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,security_owasp_crs,security_basic_auth,post_build_checks

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/inventories/group_vars/ubuntu.yml
	modified:   ansible/roles/cloud_init/files/network-config
	modified:   ansible/roles/cloud_init/files/seed.iso
	new file:   ansible/roles/docker/files/apache/diag-apache-logs.sh
	new file:   ansible/roles/docker/files/apache/webroot/db/singlesRandomPlayer.php
	renamed:    ansible/roles/docker/files/apache/webroot/singlesRandomPlayer.php -> ansible/roles/docker/files/apache/webroot/db/singlesRandomPlayer.php.old
	modified:   ansible/roles/docker/files/apache/webroot/db/upload_form.php
	modified:   ansible/roles/docker/files/apache/webroot/header.php
	deleted:    ansible/roles/docker/files/apache/webroot/singlesRandomPlayerJsonCacheClear.php
	new file:   ansible/roles/docker/files/apache/webroot/src/Controllers/RandomController.php
	new file:   ansible/roles/docker/files/apache/webroot/src/Views/media/random_player.php
	new file:   ansible/roles/docker/files/apache/webroot/src/Views/media/random_simple.php
	modified:   ios/GigHive/._GigHive.xcodeproj
	modified:   ios/GigHive/GigHive.xcodeproj/project.xcworkspace/xcuserdata/sodo.xcuserdatad/UserInterfaceState.xcuserstate
	new file:   testVideo.sh
	renamed:    ansible/vdiLockedWriteDelete.sh -> vdiLockedWriteDelete.sh

*** 
releaseNotes20250921.txt
Changes: Added iphone app and modified upload_form.php

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,security_owasp_crs,security_basic_auth,post_build_checks

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	new file:   .perm_test
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/roles/docker/files/apache/webroot/.user.ini
	modified:   ansible/roles/docker/files/apache/webroot/db/upload_form.php
	new file:   ansible/roles/docker/files/apache/webroot/db/upload_form_admin.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php
	modified:   ansible/roles/docker/files/apache/webroot/src/Views/media/list.php
	modified:   ansible/roles/docker/templates/apache2.conf.j2
	modified:   ansible/roles/docker/templates/default-ssl.conf.j2
	modified:   ansible/roles/docker/templates/modsecurity.conf.j2
	new file:   ios/GigHive/._CHECKLIST.md
	new file:   ios/GigHive/._GigHive.xcodeproj
	new file:   ios/GigHive/CHECKLIST.md
	new file:   ios/GigHive/Configs/AppInfo.plist
	new file:   ios/GigHive/Configs/GigHive.entitlements
	new file:   ios/GigHive/Configs/GigHiveShare.entitlements
	new file:   ios/GigHive/GigHive.xcodeproj/._project.xcworkspace
	new file:   ios/GigHive/GigHive.xcodeproj/project.pbxproj
	new file:   ios/GigHive/GigHive.xcodeproj/project.xcworkspace/._contents.xcworkspacedata
	new file:   ios/GigHive/GigHive.xcodeproj/project.xcworkspace/contents.xcworkspacedata
	new file:   ios/GigHive/GigHive.xcodeproj/project.xcworkspace/xcuserdata/sodo.xcuserdatad/UserInterfaceState.xcuserstate
	new file:   ios/GigHive/GigHive.xcodeproj/xcshareddata/xcschemes/._GigHive.xcscheme
	new file:   ios/GigHive/GigHive.xcodeproj/xcshareddata/xcschemes/._GigHiveShare.xcscheme
	new file:   ios/GigHive/GigHive.xcodeproj/xcshareddata/xcschemes/GigHive.xcscheme
	new file:   ios/GigHive/GigHive.xcodeproj/xcshareddata/xcschemes/GigHiveShare.xcscheme
	new file:   ios/GigHive/README.md
	new file:   ios/GigHive/Sources/App/._UploadFormView.swift
	new file:   ios/GigHive/Sources/App/GigHiveApp.swift
	new file:   ios/GigHive/Sources/App/PickerBridges.swift
	new file:   ios/GigHive/Sources/App/SettingsStore.swift
	new file:   ios/GigHive/Sources/App/UploadClient.swift
	new file:   ios/GigHive/Sources/App/UploadFormView.swift
	new file:   ios/GigHive/Sources/ShareExtension/Info.plist
	new file:   ios/GigHive/Sources/ShareExtension/ShareViewController.swift
	new file:   ios/GigHive/project.yml
	new file:   ios/GigHive/spec_20250921_145239.json
	new file:   ios/GigHive/testUpload.sh
	new file:   ios/GigHive/tools/debug_xcodegen.sh

*** 
releaseNotes20250920.txt
Changes: Timeline fix and 20240724 session database update

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,security_owasp_crs,security_basic_auth,post_build_checks

Last run: ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,security_owasp_crs,security_basic_auth,post_build_checks

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/docker/files/apache/webroot/header.php
	new file:   ansible/roles/docker/files/apache/webroot/images/jam/20250724.jpg
	modified:   ansible/roles/docker/files/apache/webroot/timeline/modern-timeline-enhanced.css
	modified:   ansible/roles/docker/files/apache/webroot/timeline/modern-timeline-enhanced.js
	modified:   ansible/roles/docker/files/apache/webroot/vodcast.xml
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/cleaned_stormpigs_database.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/cleaned_stormpigs_database_augmented.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/files.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/session_musicians.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/session_songs.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/sessions.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/song_files.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/songs.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/cleaned_stormpigs_database_augmented.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/files.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/session_musicians.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/session_songs.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/sessions.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/song_files.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/songs.csv

*** 
releaseNotes20250910.txt
Changes: Remove .props files

*** 
releaseNotes20250910.txt
Changes: Unification of webroot, fixed logic for switch in Dockerfile based on app_flavor (changes upon build: always in docker/tasks/main.yml

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,security_owasp_crs,security_basic_auth,post_build_checks

sodo@pop-os:~/scripts/gighive$ git status | head -40
On branch master
Your branch is ahead of 'origin/master' by 1 commit.
  (use "git push" to publish your local commits)

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/roles/docker/files/apache/Dockerfile

*** 
releaseNotes20250908.txt
Changes: Prune getID3 demos directory from vendor (production hardening), plus migrated blue_green source directory to html (gighive).  

Six differences shown below:
* index.php (root): This is one of the intentional differences you mentioned. We should decide which variant is canonical and keep only that difference.
* src/Views/media/list.php: This also differs. If blue_green is canonical, I can sync html’s version to match, unless you want to keep the inline CSS and small tweaks we just reverted/adjusted.
* html/src/index.php: Exists only under html/src. If this is not needed, we can remove it; otherwise, we should add it to the “overlay” list of intentional differences.
* SECURITY.html: Exists only under html. That’s fine if you want the html root to directly serve this static security page. If not needed in runtime webroot, we can exclude it.
* images/uploadutility.png: Exists only in html/images.

*** 
releaseNotes20250908.txt
Changes: README.md update

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   docs/README.md

*** 
releaseNotes20250908.txt
Changes: Add a page for coming soon instructional videos.

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   docs/comingsoon.html
	modified:   docs/index.html
 
*** 
releaseNotes20250908.txt
Changes: Add a page describing security features.

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	new file:   docs/SECURITY.md
	modified:   docs/index.html

*** 
releaseNotes20250908.txt
Changes: Deleted old files, added image for Gighive README, changed Watch Video to Video Snippet 

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/base/tasks/main.yml
	deleted:    ansible/roles/docker/files/apache/blue_green/stormpigsDatabase.php
	modified:   ansible/roles/docker/files/apache/blue_green/timeline/modern-timeline-enhanced.js
	deleted:    ansible/roles/docker/files/apache/blue_green/unified_stormpigs_database.csv
	deleted:    ansible/roles/docker/files/apache/blue_green/upload_form.php
	new file:   docs/images/uploadutility.png
	modified:   docs/index.html

*** 
releaseNotes20250908.txt
Changes: Moved upload under /db

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,security_owasp_crs,security_basic_auth,post_build_checks

Last run: ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,security_owasp_crs,security_basic_auth,post_build_checks

On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   ansible/roles/docker/files/apache/blue_green/db/upload_form.php

*** 
releaseNotes20250908.txt
Changes: Added databaseERD.png to Gighive's index page, added media size limit vars at bottom of ubuntu.yml

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/ubuntu.yml
	renamed:    docs/databaseErd.png -> docs/images/databaseErd.png
	modified:   docs/index.html

*** 
releaseNotes20250907.txt
Changes: Fixed timeline zoom in issue, still scrolls left, but we'll fix later.

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml  --tags set_targets,base,docker,post_build_checks

Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml   --skip-tags blobfuse2,vbox_provision,mysql_backup --tags set_targets,base,docker,post_build_checks -v

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/blue_green/timeline/modern-timeline-enhanced.js

*** 
releaseNotes20250907.txt
Changes: Massive amount of changes to update database to include new columns for upload, created upload api/php pages, updated composer.json and .lock manually (procedure in docs), moved csv scripts to ../dbScripts/loadutilities 

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	new file:   ansible/roles/docker/files/apache/blue_green/.user.ini
	new file:   ansible/roles/docker/files/apache/blue_green/api/uploads.php
	modified:   ansible/roles/docker/files/apache/blue_green/composer.json
	modified:   ansible/roles/docker/files/apache/blue_green/composer.lock
	new file:   ansible/roles/docker/files/apache/blue_green/docs/openapi.yaml
	new file:   ansible/roles/docker/files/apache/blue_green/docs/templates/files.csv
	new file:   ansible/roles/docker/files/apache/blue_green/docs/templates/session_songs.csv
	new file:   ansible/roles/docker/files/apache/blue_green/docs/templates/sessions.csv
	new file:   ansible/roles/docker/files/apache/blue_green/docs/templates/song_files.csv
	new file:   ansible/roles/docker/files/apache/blue_green/docs/templates/songs.csv
	deleted:    ansible/roles/docker/files/apache/blue_green/src/Controllers/BaseController.php
	deleted:    ansible/roles/docker/files/apache/blue_green/src/Controllers/FileController.php
	deleted:    ansible/roles/docker/files/apache/blue_green/src/Controllers/JamController.php
	modified:   ansible/roles/docker/files/apache/blue_green/src/Controllers/MediaController.php
	deleted:    ansible/roles/docker/files/apache/blue_green/src/Controllers/SongController.php
	new file:   ansible/roles/docker/files/apache/blue_green/src/Controllers/UploadController.php
	new file:   ansible/roles/docker/files/apache/blue_green/src/Infrastructure/FileStorage.php
	new file:   ansible/roles/docker/files/apache/blue_green/src/Repositories/FileRepository.php
	modified:   ansible/roles/docker/files/apache/blue_green/src/Repositories/SessionRepository.php
	deleted:    ansible/roles/docker/files/apache/blue_green/src/Router.php
	new file:   ansible/roles/docker/files/apache/blue_green/src/Services/UploadService.php
	deleted:    ansible/roles/docker/files/apache/blue_green/src/TestApi.php
	new file:   ansible/roles/docker/files/apache/blue_green/src/Validation/UploadValidator.php
	modified:   ansible/roles/docker/files/apache/blue_green/src/Views/media/list.php
	deleted:    ansible/roles/docker/files/apache/blue_green/src/api-docs.php
	deleted:    ansible/roles/docker/files/apache/blue_green/src/index.php
	deleted:    ansible/roles/docker/files/apache/blue_green/src/openapi.php
	deleted:    ansible/roles/docker/files/apache/blue_green/src/openapi_definition.php
	deleted:    ansible/roles/docker/files/apache/blue_green/src/routes.php
	deleted:    ansible/roles/docker/files/apache/blue_green/src/test
	deleted:    ansible/roles/docker/files/apache/blue_green/src/test-openapi.php
	deleted:    ansible/roles/docker/files/apache/blue_green/src/test_annotations.php
	modified:   ansible/roles/docker/files/apache/blue_green/timeline/timeline-api.php
	new file:   ansible/roles/docker/files/apache/blue_green/upload_form.php
	modified:   ansible/roles/docker/files/apache/blue_green/vendor/composer/autoload_classmap.php
	modified:   ansible/roles/docker/files/apache/blue_green/vendor/composer/autoload_psr4.php
	modified:   ansible/roles/docker/files/apache/blue_green/vendor/composer/autoload_static.php
	modified:   ansible/roles/docker/files/apache/blue_green/vendor/composer/installed.json
	modified:   ansible/roles/docker/files/apache/blue_green/vendor/composer/installed.php
	new file:   ansible/roles/docker/files/apache/blue_green/vendor/james-heinrich/getid3/*
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/cleaned_stormpigs_database.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/doAllFull.sh
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/doAllSample.sh
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_full.py
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_sample.py
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/cleaned_stormpigs_database_augmented.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/files.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/musicians.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/session_musicians.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/session_songs.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/sessions.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/song_files.csv
	new file:   ansible/roles/docker/files/mysql/dbScripts/loadutilities/prepped_csvs/songs.csv
	modified:   ansible/roles/docker/files/mysql/dbScripts/select.sql
	modified:   ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql
	modified:   ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/cleaned_stormpigs_database_augmented.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/files.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/sessions.csv
	new file:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/cleaned_stormpigs_database_augmented.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/files.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/sessions.csv
	modified:   ansible/roles/docker/templates/.env.j2
	new file:   docs/composerRebuild.txt
	new file:   docs/databaseErd.png
	new file:   docs/mcDatabaseERD.txt
	renamed:    docs/mvcModelmc1.txt -> docs/mcMvcModel1.txt
	renamed:    docs/mvcModelmc2.txt -> docs/mcMvcModel2.txt
	new file:   docs/mvcModel.png
	new file:   docs/mvcModel_myImplementation.png

*** 
releaseNotes20250906.txt
Changes: Jibe timeline-api.php with db/database.php's MVC structure, document MVC general/specific, fix 2006_04_06 jam files.

Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml   --skip-tags blobfuse2,vbox_provision,mysql_backup --tags set_targets,base,docker,post_build_checks
- minimal run of local vm that already exists

Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml   --skip-tags blobfuse2,vbox_provision,mysql_backup --tags set_targets,base,docker,post_build_checks -v

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/roles/docker/files/apache/blue_green/src/Controllers/MediaController.php
	new file:   ansible/roles/docker/files/apache/blue_green/src/Presentation/ViewRenderer.php
	new file:   ansible/roles/docker/files/apache/blue_green/src/Views/media/list.php
	new file:   ansible/roles/docker/files/apache/blue_green/test_render.php
	new file:   ansible/roles/docker/files/apache/blue_green/views/media/list.php
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/files.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/song_files.csv
	modified:   docs/README.md
	new file:   docs/apiStructure.txt
	new file:   docs/mvcModel.txt
	new file:   docs/mvcModelmc1.txt
	new file:   docs/mvcModelmc2.txt

*** 
releaseNotes20250906.txt
Changes: Point timeline to src/Infrastructure/Database.php and readme update.

Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml   --skip-tags blobfuse2,vbox_provision,mysql_backup --tags set_targets,base,docker,post_build_checks
- minimal run of local vm that already exists

Last run: sodo@pop-os:~/scripts/gighive$ ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml   --skip-tags blobfuse2,vbox_provision,mysql_backup --tags set_targets,base,docker,post_build_checks -v

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/blue_green/timeline/timeline-api.php
	modified:   docs/README.md

*** 
releaseNotes20250906.txt
Changes: Readme update.

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   docs/README.md
 
*** 
releaseNotes20250906.txt
Changes: Added gighive favicon and associated files: ansible/roles/docker/files/apache/html/images/icons/

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	new file:   ansible/roles/docker/files/apache/html/favicon.ico
	new file:   ansible/roles/docker/files/apache/html/images/icons/apple-touch-icon.png
	new file:   ansible/roles/docker/files/apache/html/images/icons/favicon-128.png
	new file:   ansible/roles/docker/files/apache/html/images/icons/favicon-16.png
	new file:   ansible/roles/docker/files/apache/html/images/icons/favicon-192.png
	new file:   ansible/roles/docker/files/apache/html/images/icons/favicon-256.png
	new file:   ansible/roles/docker/files/apache/html/images/icons/favicon-32.png
	new file:   ansible/roles/docker/files/apache/html/images/icons/favicon-48.png
	new file:   ansible/roles/docker/files/apache/html/images/icons/favicon-64.png
	new file:   ansible/roles/docker/files/apache/html/images/icons/favicon.ico
	modified:   ansible/roles/docker/files/apache/html/index.php

*** 
releaseNotes20250906.txt
Changes: Readme update.

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   docs/README.md

*** 
releaseNotes20250906.txt
Changes: Added new timeline, removed old one. Should reduce noise from sonarqube testing.

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml   --skip-tags blobfuse2,vbox_provision,mysql_backup --tags set_targets,base,docker,post_build_checks
- minimal run of local vm that already exists


sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   .gitignore
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/roles/docker/files/apache/blue_green/header.php
	deleted:    ansible/roles/docker/files/apache/blue_green/timeline-api.js
	deleted:    ansible/roles/docker/files/apache/blue_green/timeline.xml
	new file:   ansible/roles/docker/files/apache/blue_green/timeline/README.md
	new file:   ansible/roles/docker/files/apache/blue_green/timeline/modern-timeline-enhanced.css
	new file:   ansible/roles/docker/files/apache/blue_green/timeline/modern-timeline-enhanced.js
	new file:   ansible/roles/docker/files/apache/blue_green/timeline/timeline-api.php
	deleted:    ansible/roles/docker/files/apache/blue_green/timeline_2.3.0

*** 
releaseNotes20250905.txt
Changes: Fix for missing audio_full variable.  Tested on azure, performance improved from 50m to 15m setup time.

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/inventory_azure.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   docs/README.md
	modified:   docs/timings.txt
	modified:   terraform/tfplan

*** 
releaseNotes20250905.txt
Changes: Normalize database names to metadata standard, cascading changes for MediaController, SessionRepository, load_and_transform.sql and 9_csvprep.  Fixed timing issue with security_owasp_crs in cert creation. 

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/callback_plugins/__pycache__/vars_trace.cpython-310.pyc
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/roles/cloud_init/files/network-config
	modified:   ansible/roles/cloud_init/files/seed.iso
	modified:   ansible/roles/cloud_init/tasks/main.yml
	new file:   ansible/roles/cloud_init/tasks/main.yml.beforeLatest
	new file:   ansible/roles/cloud_init/tasks/main.yml.newcrappified
	new file:   ansible/roles/cloud_init/tasks/main.yml.workingbackup
	modified:   ansible/roles/cloud_init_disable/tasks/main.yml
	modified:   ansible/roles/docker/files/apache/blue_green/src/Controllers/MediaController.php
	new file:   ansible/roles/docker/files/apache/blue_green/src/Controllers/MediaController.php.recent
	modified:   ansible/roles/docker/files/apache/blue_green/src/Repositories/SessionRepository.php
	modified:   ansible/roles/docker/files/apache/html/src/Controllers/MediaController.php
	new file:   ansible/roles/docker/files/apache/html/src/Controllers/MediaController.php.new
	new file:   ansible/roles/docker/files/apache/html/src/Controllers/MediaController.php.recent
	modified:   ansible/roles/docker/files/apache/html/src/Repositories/SessionRepository.php
	modified:   ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql
	modified:   ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql
	new file:   ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql.new
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/sessions.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/songs.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/sessions.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/songs.csv
	modified:   ansible/roles/security_owasp_crs/tasks/verify.yml
	modified:   ansible/vdiLockedWriteDelete.sh
	modified:   docs/README.md

*** 
releaseNotes20250902.txt
Changes: Added Google Analytics to github.io

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   docs/index.html

*** 
releaseNotes20250902.txt
Changes: Removed mailgun references buried #2, fixed README

*** 
releaseNotes20250902.txt
Changes: Removed mailgun references buried

*** 
releaseNotes20250901.txt
Changes: Fixed /docs for README/PREREQS.html #2

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   docs/README.md
	modified:   docs/index.html

*** 
releaseNotes20250901.txt
Changes: Fixed /docs for README/PREREQS.html 

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   docs/index.html

*** 
releaseNotes20250901.txt
Changes: Renamed video_home to video_full to be consistent, fixed my beelogo reference png url in index.html

*** 
releaseNotes20250901.txt
Changes: To github.io docs

*** 
releaseNotes20250901.txt
Changes: Deleted old sonarcloud project..testing

*** 
releaseNotes20250901.txt
Changes: sonarcloud.properties

*** 
releaseNotes20250901.txt
Changes: gighive.io pages update #3

*** 
releaseNotes20250901.txt
Changes: base/main to remove --inplace, add sync_tags to permissions tasks, renamed sync_videos to sync_video, used apache_group var to standardize base/tasks/main.yml

Last run: ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml   --tags set_targets,base -vv

Last run: ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml   --tags set_targets,base,docker,security_basic_auth,security_owasp_crs,post_build_checks,validate_app -v

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml   --tags set_targets,sync_scripts,sync_audio,sync_video -vv

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/inventories/group_vars/ubuntu.yml
	modified:   ansible/roles/base/tasks/main.yml
	renamed:    PREREQS.md -> docs/PREREQS.md
	renamed:    README.md -> docs/README.md
	renamed:    content/logos/ChatGPT Image Aug 25, 2025, 04_28_44 PM.png -> docs/logos/ChatGPT Image Aug 25, 2025, 04_28_44 PM.png
	renamed:    content/logos/ChatGPT Image Aug 25, 2025, 04_28_46 PM.png -> docs/logos/ChatGPT Image Aug 25, 2025, 04_28_46 PM.png
	renamed:    content/logos/ChatGPT Image Aug 25, 2025, 04_28_50 PM.png -> docs/logos/ChatGPT Image Aug 25, 2025, 04_28_50 PM.png
	renamed:    content/logos/Cheerful Bee with Camera and Microphone.png -> docs/logos/Cheerful Bee with Camera and Microphone.png
	renamed:    content/logos/Cheerful Bumblebee Mascot with Gear.png -> docs/logos/Cheerful Bumblebee Mascot with Gear.png
	renamed:    content/logos/Friendly Bee Photographer with Microphone.png -> docs/logos/Friendly Bee Photographer with Microphone.png

*** 
releaseNotes20250901.txt
Changes: Removed unused code_home vars, created /docs directory

*** 
releaseNotes20250831.txt
Changes: Fixed two sources of IPs, made static_ip=ansible_host (in inventory)

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --skip-tags blobfuse2,cloud_init --ask-become-pass -v

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/inventories/group_vars/ubuntu.yml

*** 
releaseNotes20250831.txt
Changes: Edited README.txt, added .ssh checking

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --skip-tags blobfuse2,cloud_init --ask-become-pass -v

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   README.md
	modified:   ansible/roles/cloud_init_disable/tasks/main.yml

*** 
releaseNotes20250831.txt
Changes: Fixed network-config, also known_hosts issue, plus removed mailgun empty variables

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --skip-tags blobfuse2,cloud_init --ask-become-pass -v

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   README.md
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/cloud_init/defaults/main.yml
	modified:   ansible/roles/cloud_init/files/network-config
	modified:   ansible/roles/cloud_init/files/seed.iso
	modified:   ansible/roles/cloud_init/files/user-data
	modified:   ansible/roles/cloud_init/tasks/main.yml
	modified:   ansible/roles/cloud_init/tasks/main.yml.working
	new file:   ansible/roles/cloud_init/templates/user-data.j2
	modified:   ansible/roles/cloud_init_disable/tasks/main.yml
	new file:   ansible/roles/cloud_init_disable/tasks/main.yml.bak2
	modified:   ansible/roles/docker/files/apache/externalConfigs/www.conf
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	modified:   timings.txt

*** 
releaseNotes20250831.txt
Changes: Made cloud_init_disable idempotent and not dependent on an interface

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --skip-tags blobfuse2 --ask-become-pass -v

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   1prereqsInstall.sh
	modified:   README.md
	modified:   ansible/roles/cloud_init/files/network-config
	modified:   ansible/roles/cloud_init/files/seed.iso
	modified:   ansible/roles/cloud_init/tasks/main.yml
	new file:   ansible/roles/cloud_init/tasks/main.yml.beforeMacFormat
	deleted:    ansible/roles/cloud_init/tasks/main.yml.crap
	deleted:    ansible/roles/cloud_init/tasks/main.yml.testingDebug
	renamed:    ansible/roles/cloud_init/tasks/main.yml.bigMajorChanges -> ansible/roles/cloud_init/tasks/main.yml.working
	new file:   ansible/roles/cloud_init/tasks/main.yml.workingMissingUserMetaData
	new file:   ansible/roles/cloud_init/tasks/nat.yml
	new file:   ansible/roles/cloud_init/tasks/test.yml

*** 
releaseNotes20250831.txt
Changes: Deleted .bak* files and recreated README/PREREQS.md files. Moved logos under content.

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	deleted:    2bootstrap.sh.bak2
	modified:   PREREQS.md
	deleted:    PREREQS_table.md
	modified:   README.md
	deleted:    ansible/inventories/group_vars/ubuntu.yml.bak2
	deleted:    ansible/roles/cloud_init/tasks/main.yml.bak2
	deleted:    ansible/roles/cloud_init/tasks/main.yml.beforeNetworkChange
	deleted:    ansible/roles/docker/files/apache/blue_green/src/Controllers/MediaController.php.beforeRemoveSql
	deleted:    ansible/roles/docker/files/apache/blue_green/src/Controllers/MediaController.php.working
	deleted:    ansible/roles/docker/files/apache/blue_green/src/Controllers/MediaController.working
	deleted:    ansible/roles/docker/files/apache/blue_green/src/index.php.beforeSecFix
	deleted:    ansible/roles/docker/files/mysql/dbScripts/select.sql.beforeJamRemoval
	deleted:    ansible/roles/docker/files/mysql/dbScripts/select.sql.beforeUserAdd
	deleted:    ansible/roles/docker/tasks/main.yml.beforePreTaskHtpswdCreate
	deleted:    ansible/roles/security_basic_auth/tasks/main.yml.bak2
	deleted:    ansible/roles/security_basic_auth/tasks/main.yml.working
	deleted:    ansible/roles/validate_app/tasks/main.yml.beforeSecChanges
	renamed:    logos/ChatGPT Image Aug 25, 2025, 04_28_44 PM.png -> content/logos/ChatGPT Image Aug 25, 2025, 04_28_44 PM.png
	renamed:    logos/ChatGPT Image Aug 25, 2025, 04_28_46 PM.png -> content/logos/ChatGPT Image Aug 25, 2025, 04_28_46 PM.png
	renamed:    logos/ChatGPT Image Aug 25, 2025, 04_28_50 PM.png -> content/logos/ChatGPT Image Aug 25, 2025, 04_28_50 PM.png
	renamed:    logos/Cheerful Bee with Camera and Microphone.png -> content/logos/Cheerful Bee with Camera and Microphone.png
	renamed:    logos/Cheerful Bumblebee Mascot with Gear.png -> content/logos/Cheerful Bumblebee Mascot with Gear.png
	renamed:    logos/Friendly Bee Photographer with Microphone.png -> content/logos/Friendly Bee Photographer with Microphone.png


*** 
releaseNotes20250831.txt
Changes: Created verbose logging roles varscope and added configuration in ansible.cfg to support it. Added PREREQS files.

Last run: ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml   --tags set_targets,base,docker,security_basic_auth,security_owasp_crs,post_build_checks,validate_app -v

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --skip-tags blobfuse2 --ask-become-pass

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible.cfg
	new file:   ansible/callback_plugins/__pycache__/vars_trace.cpython-310.pyc
	new file:   ansible/callback_plugins/vars_trace.py
	modified:   ansible/playbooks/site.yml
	new file:   ansible/roles/varscope/defaults/main.yml
	new file:   ansible/roles/varscope/tasks/main.yml

*** 
releaseNotes20250830.txt
Changes: Fixed cloud_init formatting issue with network-config (how happened unknown), wrote new debug script vdiLockedWriteDelete.sh, moved debug scripts to debug

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --skip-tags blobfuse2 --ask-become-pass

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	deleted:    ansible/README.md
	renamed:    ansible/1unregisterDeleteVm.sh -> ansible/debug/1unregisterDeleteVm.sh
	renamed:    ansible/2unregisterDeleteVm.sh -> ansible/debug/2unregisterDeleteVm.sh
	renamed:    ansible/3uuidClosemedium.sh -> ansible/debug/3uuidClosemedium.sh
	renamed:    ansible/4vbox-active-hdds.sh -> ansible/debug/4vbox-active-hdds.sh
	renamed:    ansible/checkAll.sh -> ansible/debug/checkAll.sh
	renamed:    ansible/kill.sh -> ansible/debug/kill.sh
	modified:   ansible/roles/cloud_init/files/meta-data
	modified:   ansible/roles/cloud_init/files/network-config
	modified:   ansible/roles/cloud_init/files/seed.iso
	modified:   ansible/roles/cloud_init/files/user-data
	modified:   ansible/roles/cloud_init/tasks/main.yml
	deleted:    ansible/terraform.tfstate
	modified:   ansible/vdiLockedWriteDelete.sh
	new file:   compare-changes.sh
	new file:   diffGoodBad.txt

*** 
releaseNotes20250830.txt
Changes: Broke the virtualbox build

Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --skip-tags blobfuse2 --ask-become-pass

sodo@pop-os:~/gighiveNewEditsSince20250829$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   2bootstrap.sh
	new file:   2bootstrap.sh.bak2
	modified:   CHANGELOG.md
	modified:   README.md
	modified:   ansible.cfg
	modified:   ansible/1unregisterDeleteVm.sh
	modified:   ansible/2unregisterDeleteVm.sh
	modified:   ansible/inventories/inventory_azure.yml
	modified:   ansible/inventories/inventory_virtualbox.yml
	new file:   ansible/kill.sh
	modified:   ansible/playbooks/site.yml
	new file:   ansible/playbooks/site.yml.new
	modified:   ansible/roles/base/tasks/main.yml
	new file:   ansible/roles/cloud_init/defaults/main.yml
	modified:   ansible/roles/cloud_init/files/seed.iso
	new file:   ansible/roles/cloud_init/tasks/main.yml.bak2
	new file:   ansible/roles/cloud_init/tasks/main.yml.beforeNetworkChange
	new file:   ansible/roles/cloud_init/tasks/main.yml.bigMajorChanges
	new file:   ansible/roles/cloud_init/tasks/main.yml.crap
	new file:   ansible/roles/cloud_init/tasks/main.yml.poweroff
	new file:   ansible/roles/cloud_init/tasks/main.yml.testingDebug
	new file:   ansible/roles/cloud_init/tasks/poweroff.yml
	modified:   ansible/roles/validate_app/tasks/main.yml
	new file:   ansible/vdiLockedWriteDelete.sh
	new file:   inventory.ini
	modified:   terraform/tfplan
	new file:   timings.txt

*** 
releaseNotes20250830.txt
Changes: Testing install on Mac, I made changes to optimize ansible performance (ansible.cfg), account for id_rsa/id_ed25519 keys, fixed validate for docker check, removed newlibrary refs in shell, produced new README and changed vm name to gighive_vm in inventory file.

Last run (using new GIGHIVE_HOME): ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --skip-tags blobfuse2 --ask-become-pass

Last run (using new GIGHIVE_HOME): ansible-playbook -i ansible/inventories/inventory_azure.yml   ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2 -v 

Last run (using new GIGHIVE_HOME): ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml   --tags set_targets,base,docker,security_basic_auth,security_owasp_crs,post_build_checks,validate_app -v

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   2bootstrap.sh
	new file:   2bootstrap.sh.bak2
	modified:   CHANGELOG.md
	modified:   README.md
	modified:   ansible.cfg
	modified:   ansible/1unregisterDeleteVm.sh
	modified:   ansible/2unregisterDeleteVm.sh
	modified:   ansible/inventories/inventory_azure.yml
	modified:   ansible/inventories/inventory_virtualbox.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/cloud_init/files/seed.iso
	modified:   ansible/roles/cloud_init/files/user-data
	modified:   ansible/roles/cloud_init/tasks/main.yml
	modified:   ansible/roles/validate_app/tasks/main.yml
	new file:   inventory.ini
	modified:   terraform/tfplan

*** 
releaseNotes20250829.txt
Changes: Change to playbook for new relative GIGHIVE_HOME directory and ubuntu.yml 

Last run (using new GIGHIVE_HOME): ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml   --tags set_targets,base,docker,security_basic_auth,security_owasp_crs,post_build_checks,validate_app -v

Last run (using new GIGHIVE_HOME): ansible-playbook -i ansible/inventories/inventory_azure.yml   ansible/playbooks/site.yml --limit=gighive -vv --skip-tags vbox_provision,blobfuse2 -v 

Last run (using new GIGHIVE_HOME): ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --skip-tags blobfuse2 --ask-become-pass

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/ubuntu.yml

*** 
releaseNotes20250829.txt
Changes: Corrections for azure build, removal of unnecessary root_scripts var

Last run: ansible-playbook   -i ansible/inventories/inventory_azure.yml   ansible/playbooks/site.yml --limit=gighive -vv --skip-tags vbox_provision,blobfuse2 -v 

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   2bootstrap.sh
	modified:   CHANGELOG.md
	modified:   ansible/inventories/inventory_azure.yml
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/base/tasks/main.yml

*** 
releaseNotes20250829.txt
Changes: Added GIGHIVE_HOME and add.yml to eliminate dependency on specific absolute pathing on the ansible controller machine. Changed vars to accomodate. Also fixed security script for insecure http request. And made interpreter_python auto.
 
Last run: ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --skip-tags blobfuse2 --ask-become-pass 
Last run: ansible-playbook -i ansible/inventories/inventory_azure.yml ansible/playbooks/site.yml --skip-tags blobfuse2 --ask-become-pass 

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

sodo@pop-os:~/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	new file:   ansible.cfg
	modified:   ansible/3uuidClosemedium.sh
	modified:   ansible/4vbox-active-hdds.sh
	deleted:    ansible/afterJamRemoval.sh
	deleted:    ansible/ansible.cfg
	new file:   ansible/inventories/group_vars/all.yml
	modified:   ansible/inventories/group_vars/gighive.yml
	deleted:    ansible/playbooks/debug.yml
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/cloud_init/files/seed.iso
	modified:   ansible/roles/cloud_init/files/user-data
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/security_basic_auth/tasks/main.yml
	new file:   ansible/roles/security_basic_auth/tasks/main.yml.bak2

*** 
releaseNotes20250826.txt
Changes: Gui changes to remove Gighive title, added beelogo, updated database, secured video/audio directories, variablized gighive_server_alias, files alias

Last run: ansible-playbook playbooks/site.yml -i inventories/inventory_baremetal.yml   --tags set_targets,base,docker,security_basic_auth,security_owasp_crs,post_build_checks,validate_app -v

Last run: ansible-playbook playbooks/site.yml -i inventories/inventory_virtualbox.yml   --tags set_targets,base -v

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/inventories/group_vars/ubuntu.yml
	modified:   ansible/roles/docker/files/apache/blue_green/src/Controllers/MediaController.php
	new file:   ansible/roles/docker/files/apache/html/images/beelogo.png
	modified:   ansible/roles/docker/files/apache/html/index.php
	modified:   ansible/roles/docker/files/apache/html/src/Controllers/MediaController.php
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/sessions.csv
	modified:   ansible/roles/docker/templates/default-ssl.conf.j2
	modified:   ansible/roles/docker/templates/openssl_san.cnf.j2
	modified:   ansible/roles/base/tasks/main.yml
	new file:   logos/ChatGPT Image Aug 25, 2025, 04_28_44 PM.png
	new file:   logos/ChatGPT Image Aug 25, 2025, 04_28_46 PM.png
	new file:   logos/ChatGPT Image Aug 25, 2025, 04_28_50 PM.png
	new file:   logos/Cheerful Bee with Camera and Microphone.png
	new file:   logos/Cheerful Bumblebee Mascot with Gear.png
	new file:   logos/Friendly Bee Photographer with Microphone.png

*** 
releaseNotes20250823.txt
Changes: running on ubuntu bare metal box, put in code to allow --check to proceed, update basic_auth for gighive.htpasswd incorrect directory

Last run was: ansible-playbook playbooks/site.yml -i inventories/inventory_baremetal.yml   --tags set_targets,base,docker,security_basic_auth,security_owasp_crs,post_build_checks,validate_app,mysql_backup -v

Also, when we did Azure, Azure bombed on same incorrect directory.  So finally fixed it

Last run was: ansible-playbook playbooks/site.yml -i inventories/inventory_azure.yml   --tags set_targets,docker,security_basic_auth,security_owasp_crs,post_build_checks,validate_app,mysql_backup -

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/inventories/group_vars/ubuntu.yml
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/security_basic_auth/tasks/main.yml
	modified:   ansible/roles/security_owasp_crs/tasks/verify.yml

Changes not staged for commit (azure changes):
  (use "git add <file>..." to update what will be committed)
  (use "git restore <file>..." to discard changes in working directory)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/inventories/inventory_azure.yml
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/security_basic_auth/tasks/main.yml
	modified:   terraform/tfplan
*** 
releaseNotes20250823.txt
Changes: Fixed apache2.conf for logging, fixed Dockerfile and entrypoint for mpm-event to support http2, removed forgotPassword in favor of just running security_basic_auth again (last push)

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/Dockerfile
	deleted:    ansible/roles/docker/files/apache/blue_green/forgotPassword.php
	modified:   ansible/roles/docker/files/apache/externalConfigs/logging.conf
	deleted:    ansible/roles/docker/files/apache/html/app/forgotPassword.php
	modified:   ansible/roles/docker/templates/apache2.conf.j2
	modified:   ansible/roles/docker/templates/entrypoint.sh.j2

*** 
releaseNotes20250823.txt
Changes: Amended security_basic_auth to reset the password to original on a second run, fixed a var issue with jam.js, included a new gighive_apache_container var 

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/docker/files/apache/Dockerfile
	modified:   ansible/roles/docker/files/apache/blue_green/jam.js
	modified:   ansible/roles/security_basic_auth/tasks/main.yml
	new file:   ansible/roles/security_basic_auth/tasks/main.yml.working

*** 
releaseNotes20250823.txt
Changes: Removed SP from main, renamed web_auth_basic to security_basic_auth, renamed apache_security to security_owasp_crs

Last run: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml   --tags set_targets,base,security_basic_auth,docker,security_owasp_crs,post_build_checks,validate_app,mysql_backup -v
Last run: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml   --skip_tags vbox_provision,blobfuse2

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/inventories/group_vars/ubuntu.yml
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/base/tasks/main.yml
	renamed:    ansible/roles/web_auth_basic/defaults/main.yml -> ansible/roles/security_basic_auth/defaults/main.yml
	renamed:    ansible/roles/web_auth_basic/handlers/main.yml -> ansible/roles/security_basic_auth/handlers/main.yml
	renamed:    ansible/roles/web_auth_basic/meta/main.yml -> ansible/roles/security_basic_auth/meta/main.yml
	renamed:    ansible/roles/web_auth_basic/tasks/main.yml -> ansible/roles/security_basic_auth/tasks/main.yml
	renamed:    ansible/roles/apache_security/defaults/main.yml -> ansible/roles/security_owasp_crs/defaults/main.yml
	renamed:    ansible/roles/apache_security/handlers/main.yml -> ansible/roles/security_owasp_crs/handlers/main.yml
	renamed:    ansible/roles/apache_security/tasks/main.yml -> ansible/roles/security_owasp_crs/tasks/main.yml
	renamed:    ansible/roles/apache_security/tasks/verify.yml -> ansible/roles/security_owasp_crs/tasks/verify.yml
 
*** 
releaseNotes20250822.txt
Changes: Add password change page for admin upon first load (lots of changes needed), new index.php with marketing message, new changethepassword.php page, fixed db backup script for date issue, added timings for azure spinup.

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   2bootstrap.sh
	modified:   3deleteAll.sh
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/inventories/inventory_azure.yml
	modified:   ansible/roles/docker/files/apache/Dockerfile
	new file:   ansible/roles/docker/files/apache/html/changethepasswords.php
	new file:   ansible/roles/docker/files/apache/html/changethepasswords.php.bak2
	modified:   ansible/roles/docker/files/apache/html/index.php
	new file:   ansible/roles/docker/files/apache/html/index.php.bak2
	deleted:    ansible/roles/docker/files/mysql/dbScripts/backups/music_db_2025-08-08.sql.gz
	deleted:    ansible/roles/docker/tasks/before.RemoveProjectName
	modified:   ansible/roles/docker/tasks/main.yml
	renamed:    ansible/roles/docker/templates/vhost.conf.j2 -> ansible/roles/docker/templates/default-ssl.conf.j2
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	modified:   ansible/roles/mysql_backup/templates/dbDump.sh.j2
	new file:   ansible/roles/mysql_backup/templates/dbDump.sh.j2.bak2
	modified:   ansible/roles/web_auth_basic/tasks/main.yml
	modified:   terraform/tfplan

*** 
releaseNotes20250822.txt
Changes: simplified index.php and database.php to only those two files, changed default password to remove -

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	renamed:    ansible/roles/docker/files/apache/blue_green/db/indexMediaController.refactored.php -> ansible/roles/docker/files/apache/blue_green/db/database.php
	deleted:    ansible/roles/docker/files/apache/blue_green/db/indexIntegratedSql.php
	deleted:    ansible/roles/docker/files/apache/blue_green/db/indexMediaController.php
	modified:   ansible/roles/docker/files/apache/blue_green/header.php
	deleted:    ansible/roles/docker/files/apache/blue_green/indexGighive.php
	renamed:    ansible/roles/docker/files/apache/html/db/indexMediaController.php -> ansible/roles/docker/files/apache/html/db/database.php
	deleted:    ansible/roles/docker/files/apache/html/db/indexIntegratedSql.php
	modified:   ansible/roles/docker/files/apache/html/index.php

*** 
releaseNotes20250820.txt
Changes: Migrated blue_green changes from last push to html directory, disabled blue_green and reduced database size

Last run: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml   --tags set_targets,base,web_auth_basic,docker,apache_security,post_build_checks,validate_app -v

Plus a db rebuild for the smaller database

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/roles/docker/files/apache/html/index.php
	modified:   ansible/roles/docker/files/apache/html/composer.json
	modified:   ansible/roles/docker/files/apache/html/src/Controllers/MediaController.php
	deleted:    ansible/roles/docker/files/apache/html/src/Controllers/MediaController.php.afterJamRemoval
	deleted:    ansible/roles/docker/files/apache/html/src/Controllers/MediaController.php.beforeJamRemoval
	deleted:    ansible/roles/docker/files/apache/html/src/Database/Database.php
	new file:   ansible/roles/docker/files/apache/html/src/Infrastructure/Database.php
	new file:   ansible/roles/docker/files/apache/html/src/Repositories/SessionRepository.php

*** 
releaseNotes20250820.txt
Changes: Switch to b/g, composer minor path, header css fix, jam var declare, refactored chain (indexRepo for SQL/data access logic, Infra for tech svcs like DB conn, MediaController for HTTP requests/Responses, docker-compose apache env fix

Last run: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml   --tags set_targets,base,web_auth_basic,docker,apache_security,post_build_checks,validate_app -v

sodo@pop-os:~/scripts/gighive$ git diff-tree --no-commit-id --name-only -r HEAD
CHANGELOG.md
ansible/inventories/group_vars/gighive.yml
ansible/roles/docker/files/apache/blue_green/composer.json
ansible/roles/docker/files/apache/blue_green/db/indexMediaController.refactored.php
ansible/roles/docker/files/apache/blue_green/header.php
ansible/roles/docker/files/apache/blue_green/jam.js
ansible/roles/docker/files/apache/blue_green/src/Controllers/MediaController.php
ansible/roles/docker/files/apache/blue_green/src/Controllers/MediaController.php.beforeRemoveSql
ansible/roles/docker/files/apache/blue_green/src/Database/Database.php
ansible/roles/docker/files/apache/blue_green/src/Infrastructure/Database.php
ansible/roles/docker/files/apache/blue_green/src/Repositories/SessionRepository.php
ansible/roles/docker/templates/docker-compose.yml.j2

*** 
releaseNotes20250819.txt
Changes: Security remediations, round #2.  Minor changes to remove font-color and variable declaration for jam.js 

Last run: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml   --tags set_targets,base,docker,post_build_checks,validate_app -vv

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/roles/docker/files/apache/blue_green/header.css
	modified:   ansible/roles/docker/files/apache/blue_green/jam.js

*** 
releaseNotes20250818.txt
Changes: Security remediations, round #1
- remove mailgun from site.yml , forgot password dummy vars, patched blue_green/src/index.php with protected logging, openapispec move

sodo@pop-os:~/scripts/gighive$ git status | egrep 'new|modified'
	modified:   CHANGELOG.md
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/docker/files/apache/blue_green/forgotPassword.php
	modified:   ansible/roles/docker/files/apache/blue_green/src/index.php
	modified:   ansible/roles/docker/files/apache/html/app/forgotPassword.php
	new file:   ansible/roles/docker/files/api/openapi.yaml
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/validate_app/tasks/main.yml
	new file:   ansible/roles/validate_app/tasks/main.yml.beforeSecChanges


*** 
releaseNotes20250817.txt
Changes: Sonarqube org name to gighive

sodo@pop-os:~/scripts/gighive$ git commit -m "fixed name of org to gighive"
[master d83b183] fixed order of org/project key in sonarcloud config
 1 file changed, 1 insertion(+), 1 deletion(-)
sodo@pop-os:~/scripts/gighive$ git push
Enumerating objects: 5, done.
Counting objects: 100% (5/5), done.
Delta compression using up to 16 threads
Compressing objects: 100% (3/3), done.
Writing objects: 100% (3/3), 333 bytes | 333.00 KiB/s, done.
Total 3 (delta 2), reused 0 (delta 0), pack-reused 0
remote: Resolving deltas: 100% (2/2), completed with 2 local objects.
To github.com:frases/gighive.git
   c8ce98b..d83b183  master -> master

*** 
releaseNotes20250817.txt
Changes: SonarCloud CI + cleanup of .bak and dir structure


*** 
releaseNotes20250817.txt
Changes: [master 88a2554] Cleaned up repo to delete bak, zip and assorted files

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	deleted:    ansible.zip
	deleted:    ansible/files.txt
	deleted:    ansible/lastRun20250725.txt
	deleted:    ansible/roles/apacheSec.zip
	deleted:    ansible/roles/auth.zip
	deleted:    ansible/roles/base/tasks/main.yml.beforeVideoLogicChange
	deleted:    ansible/roles/blobfuse2/defaults/main.yml.beforeRenameVars
	deleted:    ansible/roles/cloud_init/tasks/main.yml.beforeTagAdd
	deleted:    ansible/roles/cloud_init/tasks/main.ymlSshOnlyNoRootLogin
	deleted:    ansible/roles/docker/tasks/main.yml.beforeApiChanges
	deleted:    ansible/roles/docker/tasks/main.yml.beforeUserAdd
	deleted:    ansible/roles/post_build_checks/tasks/main.yml.bak2
	deleted:    ansible/roles/post_build_checks/tasks/main.yml.bak3
	deleted:    apibackup/BaseController.php
	deleted:    apibackup/FileController.php
	deleted:    apibackup/JamController.php
	deleted:    apibackup/SongController.php
	deleted:    beforeRollingOutToMasses.txt
	deleted:    federatedAuth/explanation.txt
	deleted:    federatedAuth/userProfileSampleForGithub.txt
	deleted:    openapi.yaml
	new file:   sonar-project.properties

*** 
releaseNotes20250816.txt
Changes: [master 71152b7] Tested Azure build, fixed vm disk size to 64GB, fixed comment in bootstrap

Last run (from ./2bootstrap.sh): cd ansible && ansible-playbook   -i inventories/inventory_azure.yml   playbooks/site.yml --limit=gighive -vv --skip-tags vbox_provision,blobfuse2

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   2bootstrap.sh
	modified:   CHANGELOG.md
	modified:   ansible/inventories/inventory_azure.yml
	modified:   terraform/main.tf
	modified:   terraform/tfplan
 5 files changed, 11 insertions(+), 2 deletions(-

*** 
releaseNotes20250815.txt
Changes: Added mod_security

Last run: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml   --tags set_targets,base,docker,apache_security,validate_app -vv
Last run: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml   --tags set_targets,verify_apache_security -vv

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes to be committed:
  (use "git restore --staged <file>..." to unstage)
	modified:   CHANGELOG.md
	modified:   ansible/playbooks/site.yml
	new file:   ansible/roles/apacheSec.zip
	new file:   ansible/roles/apache_security/defaults/main.yml
	new file:   ansible/roles/apache_security/handlers/main.yml
	new file:   ansible/roles/apache_security/tasks/main.yml
	new file:   ansible/roles/apache_security/tasks/verify.yml
	modified:   ansible/roles/docker/files/apache/Dockerfile
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/docker/templates/apache2.conf.j2
	new file:   ansible/roles/docker/templates/crs-setup.conf.j2
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	new file:   ansible/roles/docker/templates/modsecurity.conf.j2
	new file:   ansible/roles/docker/templates/security2.conf.j2

*** 
releaseNotes20250810.txt
Changes: Made vhost.j2, entrypoint.sh, apache2.conf, openssl_san.cnf, gighive.htpasswd into jinja templates that gets mounted in docker-compose. Removed cacasododom domain from opensslconf, and added a 4th script to identify vmdk

Last run: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml --tags set_targets,cloud_init,base,web_auth_basic,docker,post_build_checks,validate_app --ask-become-pass

Note the local vm takes up about 40GB of space, tho 250GB is allocated

sodo@pop-os:~/scripts/gighive$ git status | grep modified
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/docker/files/apache/externalConfigs/openssl_san.cnf
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/cloud_init/files/seed.iso
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	modified:   ansible/roles/docker/templates/vhost.conf.j2
	modified:   ansible/roles/post_build_checks/tasks/main.yml
	modified:   ansible/roles/web_auth_basic/tasks/main.yml

Untracked files:
  (use "git add <file>..." to include in what will be committed)
	ansible/roles/docker/files/apache/externalConfigs/apache2-logrotate.conf
	ansible/roles/docker/templates/apache2.conf.j2
	ansible/roles/docker/templates/entrypoint.sh.j2
	ansible/roles/docker/templates/vhost.conf.j2
	ansible/4vbox-active-hdds.sh
	ansible/roles/docker/templates/gighive.htpasswd.j2
	ansible/roles/docker/templates/openssl_san.cnf.j2

*** 
releaseNotes20250810.txt
Changes: Added variables for gighive hosts used in post_build_checks to make it easier to test the site and updated bootstrap to include note about adding a hosts file entry once the server is built in Azure.

gighive_scheme: "https"
gighive_host: "gighive"                  # can be an IP or hostname
gighive_validate_certs: false
gighive_hostname_for_host_header: ""     # set to "gighive" when using IP
gighive_base_url: "{{ gighive_scheme }}://{{ gighive_host }}"

sodo@pop-os:~/scripts/gighive$ git status | grep modified
	modified:   2bootstrap.sh
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/roles/post_build_checks/tasks/main.yml

*** 
releaseNotes20250810.txt
Changes: Enable basic_authentication, update vhost.j2, add variables to group_vars, create new role, create htpasswd file, restart Apache, lots of post_build_checks

Last run: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml   --tags set_targets,base,web_auth_basic,docker,post_build_checks  
     and: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml   --tags set_targets,base,web_auth_basic,docker,post_build_checks   --list-tasks

sodo@pop-os:~/scripts/gighive$ git status | grep modified
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/docker/files/apache/externalConfigs/vhost.j2
	modified:   ansible/roles/docker/files/apache/html/app/singlesRandomPlayerJsonCacheClear.php
	modified:   ansible/roles/docker/files/apache/html/src/index.php
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	modified:   ansible/roles/post_build_checks/tasks/main.yml

To Do: make the variables generic
To Do: password protect detailed db pages

*** 
releaseNotes20250809.txt
Changes: In prep for basic auth, divide dirs/files into public private, backup the working html dir into html_working. Singlescacheclear.php reference changed.

Last run: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml --tags set_targets,base,docker,post_build_checks -v

sodo@pop-os:~/scripts/gighive$ git status | grep modified
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/roles/docker/files/apache/html_working/src/Controllers/MediaController.php
	modified:   ansible/roles/post_build_checks/tasks/main.yml

*** 
releaseNotes20250809.txt
Changes: Simplified when: statements under base/main and docker templates for sync_videos, changed video_dir directory and docker-compose.yml.j2 template to reference, removed outdated tasks/comments, added backup restore

# Since I edited audio_dir settings in docker-compose.yml.j2, have to re-run docker
Last run was: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml --tags set_targets,base,docker

What changed?
sodo@pop-os:~/scripts/gighive$ git status | grep modified
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	modified:   ansible/roles/mysql_backup/tasks/main.yml
	modified:   ansible/roles/mysql_backup/templates/dbDump.sh.j2
	ansible/roles/docker/tasks/overlay_gighive_webroot.yml
	ansible/roles/mysql_backup/templates/dbRestore.sh.j2

sodo@pop-os:~/scripts/gighive/ansible/roles/mysql_backup/templates$ ll
total 12
-rw-rw-r-- 1 sodo sodo 2694 Aug  9 08:33 dbDump.sh.j2
-rw-rw-r-- 1 sodo sodo 3964 Aug  9 08:52 dbRestore.sh.j2

Next: add authentication, add sessions home page underneath jam timeline

*** 
releaseNotes20250808.txt
Changes: Fixed 9_csvprep files, fix sort issue for mp3 jams of > 10 files, remove link if no file

- don't forget that docker-compose.yml needs to be recreated if you change the database from full to sample or blue_green or modify an html file
- fix timeline by outputting "<event id" syntax (grab awk example for chatgpt to turn into python): extractSimilePython.py
- create a daily backup from dbDump.sh on the docker host and prune the log once every three months
    - there is a new role, mysql_backup that is dependent upon docker, but if docker already up, then you can just run it
    - this daily backup adds a cron entry on the host 
- also created a sync_audio set of tasks and variables (they need docker to run as I edited docker-compose.yml.j2)

sodo@pop-os:~/scripts/gighive/ansible$ cat inventories/group_vars/gighive.yml 
video_home: "/home/sodo/videos/stormpigs/finals/singles/"
audio_home: "/home/sodo/scripts/stormpigsCode/production/audio/"
role_path: "/home/sodo/scripts/gighive/ansible/roles/docker"
ansible_remote_tmp: "/tmp/.ansible/tmp"
blue_green: true
database_full: true
sync_videos: false
sync_audio: true
reduced_videos: true
reduced_audio: true

site.yml
        video_dir: "/home/{{ ansible_user }}/videos/stormpigs/finals/singles"
        audio_dir: "/home/{{ ansible_user }}/audio"

removed conditionals in docker-compose.yml.j2
{% if sync_videos %}
      - "/home/{{ ansible_user }}/videos/stormpigs/finals/singles:/var/www/html/video"
{% endif %}
{% if sync_audio %}
      - "/home/{{ ansible_user }}/audio:/var/www/html/audio"
{% endif %}

and then added three tasks in sync_audio in main

Last run was: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml --tags set_targets,base,mysql_backup --skip-tags docker

changes:
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/base/tasks/main.yml
	new file:   ansible/roles/docker/files/apache/blue_green/images/jam/20250603.jpg
	new file:   ansible/roles/docker/files/apache/blue_green/images/jam/current.jpg
	modified:   ansible/roles/docker/files/apache/blue_green/indexIntegratedSql.php
	modified:   ansible/roles/docker/files/apache/blue_green/timeline.xml
	new file:   ansible/roles/docker/files/mysql/dbScripts/backupSanity.sh
	new file:   ansible/roles/docker/files/mysql/dbScripts/backups/music_db_2025-08-08.sql.gz
	new file:   ansible/roles/docker/files/mysql/dbScripts/dbDump.sh
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/files.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/files.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/musicians.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/session_musicians.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/session_songs.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/sessions.csv
	modified:   ansible/roles/docker/files/rebuildForDb.sh
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	new file:   ansible/roles/mysql_backup/meta/main.yml
	new file:   ansible/roles/mysql_backup/tasks/main.yml
	new file:   ansible/roles/mysql_backup/templates/dbDump.sh.j2

*** 
releaseNotes20250807.txt
Changes: Added database_full boolean to gighive inventory to toggle full/sample database load and associated docker-compose change, reverted 9_csvprep python because file order was incorrect, renamed database* html files to index*

Last run was: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml --tags set_targets,base,docker -v
- don't forget that docker-compose.yml needs to be recreated if you change the database from full to sample or blue_green
- use in conjuntion with rebuildForDb.sh

- Note that I will be abandoning my 0-8 scripts in favor of moving ahead with the consolidated database csv..easier than dealing with creating the database from all those python scripts. 

- still have to fix two issues: fix sort issue for mp3 jams of > 10 files, remove link if no file
- still have to fix the timeline by outputting "<event id" syntax (grab awk example for chatgpt to turn into python), like /home/sodo/20250603/extractCsvFromVodcastXml.py
- have to fix the fact that the mp3 files are not included in the video sync..maybe make a dual version of the sync for the audio files..
- create a daily backup from dbDump.sh on the docker host and prune the log once every three months

sodo@pop-os:~/scripts/gighive$ git status | grep modified
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/roles/cloud_init/files/seed.iso
	modified:   ansible/roles/docker/files/apache/externalConfigs/openssl_san.cnf
	modified:   ansible/roles/docker/files/apache/externalConfigs/ports.conf
	modified:   ansible/roles/docker/files/apache/externalConfigs/vhost.j2
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/files.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/song_files.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/session_musicians.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/session_songs.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/sessions.csv
	modified:   ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/songs.csv
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
        renamed:    ansible/roles/docker/files/apache/blue_green/indexIntegratedSql.php
	renamed:    ansible/roles/docker/files/apache/blue_green/indexMediaController.php
	renamed:    ansible/roles/docker/files/apache/html/indexIntegratedSql.php
	renamed:    ansible/roles/docker/files/apache/html/indexMediaController.php

*** 
releaseNotes20250805.txt
Fixing vhost.j2 and ports.conf because apache listening on IPv6 was returning old stormpigs homepage 
This was due to the FIOS router's DNS cache getting corrupted for gighive with IPv6 addresses.
Also, I edited gighive thus:
 sync_videos: false
 blue_green: false
 reduced_videos: true

Last run was: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml --skip-tags vbox_provision,blobfuse2 -vv
Previous run was: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml --skip-tags blobfuse2 -vv

# =================================================================
# IPv4‐only listener (binds on 0.0.0.0:443 to disable IPv6 traffic)
# Docker-compose is publishing 0.0.0.0:443:443, so Apache won’t see any [::]:443 binds
# =================================================================
Listen 0.0.0.0:443
Protocols h2 http/1.1

These files changed:
	modified:   ../CHANGELOG.md
	modified:   inventories/group_vars/gighive.yml
	modified:   roles/cloud_init/files/seed.iso
	modified:   roles/docker/files/apache/externalConfigs/openssl_san.cnf
	modified:   roles/docker/files/apache/externalConfigs/ports.conf
	modified:   roles/docker/files/apache/externalConfigs/vhost.j2
	modified:   roles/docker/templates/docker-compose.yml.j2

*** 
releaseNotes20250804.txt
Ubuntu bare metal build, bunch of fixes to allow gighive and ubuntu boxes to be built without problems

Last run was: ansible-playbook playbooks/site.yml -i inventories/inventory_baremetal.yml   --tags set_targets,base,docker,post_build_checks,validate_app -vvv
Last run was: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml --tags set_targets,base,docker,post_build_checks,validate_app -vvv
Don't forget vars for sync_videos, blue_green and reduced_videos are in group_vars

These files changed:
	modified:   CHANGELOG.md
	modified:   ansible/ansible.cfg
	modified:   ansible/inventories/group_vars/ubuntu.yml
	modified:   ansible/inventories/inventory_baremetal.yml
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/cloud_init/tasks/main.yml
	modified:   ansible/roles/docker/files/apache/externalConfigs/openssl_san.cnf
	modified:   ansible/roles/post_build_checks/tasks/main.yml

*** 
releaseNotes20250803.txt
WHAT: Moved cloud_init, video_sync vars, MediaController as UX
Last run was: ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml --tags set_targets,base,docker,post_build_checks,validate_app -vvv

sodo@pop-os:~/scripts/gighive$ git status | grep modified | grep -v prepped
	modified:   CHANGELOG.md
	modified:   ansible/inventories/group_vars/gighive.yml
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/cloud_init/files/seed.iso
	modified:   ansible/roles/docker/files/apache/Dockerfile
	modified:   ansible/roles/docker/files/apache/blue_green/src/Controllers/MediaController.php
	modified:   ansible/roles/docker/templates/docker-compose.yml.j2
	modified:   ansible/roles/post_build_checks/tasks/main.yml

I moved cloud_init to root filesystem
Converted new SP database page to use old gui

I made variables for video sync that affected group_vars, site.yml, docker_compose.yml.j2 and base/tasks/main.yml
- Full sync in blue-green mode when you need all 508 files
- Reduced subset whenever you’re not in blue-green or you’ve explicitly asked for reduced_videos
- No sync when sync_videos: false

Variables are here: inventories/group_vars/gighive.yml
  sync_videos:   true      # not "true"
  blue_green:    true
  reduced_videos: false

Changed docker compose to this:
{% if sync_videos %}
      - "/home/ubuntu/videos/stormpigs/finals/singles:/var/www/html/video:ro"
{% endif %}

Related variables:
sodo@pop-os:~/scripts/gighive/ansible$ cat inventories/goup_vars/gighive.yml | grep video
video_home: "/home/sodo/videos/stormpigs/finals/singles/"

sodo@pop-os:~/scripts/gighive/ansible$ cat playbooks/site.yml | grep video
        video_dir: "/home/{{ ansible_user }}/videos/stormpigs/finals/singles"

And base/tasks/main.yml 
- name: Sync full StormPigs video directory (all 508 files)
  delegate_to: localhost
  become: false
  synchronize:
    mode: push
    src:      "{{ video_home }}/"
    dest:     "{{ video_dir }}"
    archive:  yes
    compress: yes
    copy_links: yes
    delay_updates: false
    rsync_opts:
      - "--inplace"
      - "--no-p"
      - "--no-o"
      - "--no-g"
    _ssh_args: "-o StrictHostKeyChecking=no -o ServerAliveInterval=60 -o ServerAliveCountMax=3"
    use_ssh_args: true
  when:
    - sync_videos
    - blue_green             # only in blue/green mode
    - not reduced_videos     # and only if not explicitly asking for reduced

- name: Sync reduced StormPigs video directory (development subset)
  delegate_to: localhost
  become: false
  synchronize:
    mode: push
    src:      "{{ video_home }}/"
    dest:     "{{ video_dir }}"
    archive:  yes
    compress: yes
    copy_links: yes
    delay_updates: false
    rsync_opts:
      - "--inplace"
      - "--include=StormPigs20021024*"
      - "--include=StormPigs202[45]*"
      - "--exclude=*"
      - "--no-p"
      - "--no-o"
      - "--no-g"
    _ssh_args: "-o StrictHostKeyChecking=no -o ServerAliveInterval=60 -o ServerAliveCountMax=3"
    use_ssh_args: true
  when:
    - sync_videos
    - >-
      (not blue_green) or reduced_videos

Next: need to repair ubuntu machine
*** 
releaseNotes20250802.txt

full build on bare metal : clear;ansible-playbook -i inventories/inventory_baremetal.yml playbooks/site.yml  --tags set_targets,base,docker,blue_green,post_build_checks,validate_app -vv

full build of vm: clear;ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml  --tags set_targets,cloud_init,base,docker,blue_green,post_build_checks,validate_app --ask-become-pass  -vv

*** 
releaseNotes20250731.txt
Bunch of fixes brought upon me testing the azure build
sodo@pop-os:~/scripts/gighive$ git status | grep modified
	modified:   2bootstrap.sh
	modified:   ansible/inventories/inventory_azure.yml
	modified:   ansible/playbooks/site.yml
	modified:   ansible/roles/base/tasks/main.yml
	modified:   ansible/roles/docker/files/apache/Dockerfile
	modified:   ansible/roles/docker/files/apache/blue_green/stormpigsDatabaseDbVer.php
	modified:   ansible/roles/docker/files/apache/blue_green/stormpigsDatabaseDbVerNew.php
	modified:   ansible/roles/docker/tasks/main.yml
	modified:   ansible/roles/post_build_checks/tasks/main.yml
	modified:   ansible/roles/validate_app/tasks/main.yml
	modified:   terraform/tfplan
*** 
releaseNotes20250730.txt
Moved blue_green into group_vars/gighive.yml.  Use in combination with tag of same name
Fixed dict error on mysql_info in [docker]
Deleted bak/before files in playbooks/inventories/group_vars

blue_green is false below (in other words, normal gighive html)
ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml -vv --tags set_targets,docker

blue_green is true below (in other words, alternate html)
ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml -vv --tags set_targets,docker,blue_green

***
releaseNotes20250728.txt
Changed STORMPIGS_OVERRIDE to BLUE_GREEN reference, changed stormpigs_override to blue_green variable, renamed directory from ansible/roles/docker/files/apache/stormpigs_override/ to ansible/roles/docker/files/apache/blue_green/

 2028  git diff master~1..master -- ansible/roles/docker/files/apache/Dockerfile
 2029  git diff master~1..master -- ansible/roles/docker/tasks/overlay_gighive.yml
 2030  git diff master~1..master -- ansible/roles/docker/templates/docker-compose.yml.j2

blue_green also include database load script, but since the database is created on new image build, you have to build a new image:
      - "{{ docker_dir }}/mysql/externalConfigs/prepped_csvs/{{ 'full' if blue_green | bool else 'sample' }}:/var/lib/mysql-files/"

docker-compose down -v
docker-compose build
docker-compose up -d

Sets web_root to blue_green override:
clear;ansible-playbook   -i inventories/inventory_virtualbox.yml playbooks/site.yml -e blue_green=true --tags set_targets,docker,blue_green -vvv

Sets web_root to gighive:
clear;ansible-playbook -i inventories/inventory_virtualbox.yml playbooks/site.yml -e blue_green=false -vv --tags set_targets,docker

***
releaseNotes20250727.txt
I used a script to copy the diffs between stormpigs and gighive webroot files into stormpigs_overrides as below

Learned new list-tags option: ansible-playbook --check  -i inventories/inventory_virtualbox.yml  playbooks/site.yml --limit=gighive  -e stormpigs_overrides=false -vv --tags set_targets,docker --list-tasks

Sets web_root to stormpigs override:
clear;ansible-playbook   -i inventories/inventory_virtualbox.yml   playbooks/site.yml   -e stormpigs_overrides=true   --tags set_targets,docker,stormpigs   -vvv

Sets web_root to gighive:
clear;ansible-playbook -i inventories/inventory_virtualbox.yml  playbooks/site.yml --limit=gighive  -e stormpigs_overrides=false -vv --tags set_targets,docker

Triggered by: ansible-playbook   -i inventories/inventory_virtualbox.yml --check  playbooks/site.yml --limit=gighive -vv --tags set_targets,stormpigs* note the check

Here are the changes
sodo@pop-os:~/scripts/gighive/ansible$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes not staged for commit:
  (use "git add <file>..." to update what will be committed)
  (use "git restore <file>..." to discard changes in working directory)
	modified:   ../CHANGELOG.md
	modified:   roles/docker/tasks/main.yml

Untracked files:
  (use "git add <file>..." to include in what will be committed)
	roles/docker/tasks/overlay_stormpigs.yml

./copySpOverrides.sh 
Directory exists
total 0
sodo@pop-os:~/scripts/gighive$ ll ~/scripts/gighive/ansible/roles/docker/files/apache/stormpigs_overrides
total 396
-rw-r--r-- 1 sodo sodo    766 Mar 16  2007 favicon.ico
-rw-r--r-- 1 sodo sodo   1697 Jan 19  2025 header.css
-rw-r--r-- 1 sodo sodo   4963 Jan 23  2025 header.php
drwxr-xr-x 4 sodo sodo   4096 Jan 25  2025 images
-rw-r--r-- 1 sodo sodo   9278 Jan 12  2025 jam.js
-rw-r--r-- 1 sodo sodo  13345 Jan 12  2025 jamList.js
-rw-r--r-- 1 sodo sodo    392 Jan 12  2025 loops.php
-rw-r--r-- 1 sodo sodo   7641 Dec  8  2024 simile-ajax-api.js
-rw-r--r-- 1 sodo sodo   3038 Jun  6 11:10 stormpigsDatabaseDbVer.php
-rw-r--r-- 1 sodo sodo   7647 Mar  9 11:07 stormpigsDatabase.php
drwxr-xr-x 4 sodo sodo   4096 Dec 23  2024 timeline_2.3.0
-rw-r--r-- 1 sodo sodo  11002 Dec  8  2024 timeline-api.js
-rw-r--r-- 1 sodo sodo  51653 Dec 15  2024 timeline.xml
-rw-r--r-- 1 sodo sodo 177967 May 31 15:00 unified_stormpigs_database.csv
-rw-r--r-- 1 sodo sodo  78160 Dec 19  2024 vodcast.xml
Plus
sodo@pop-os:~/scripts/gighive$ cp -rp /home/sodo/scripts/stormpigsCode/production/index.php ~/scripts/gighive/ansible/roles/docker/files/apache/stormpigs_overrides

***
releaseNotes20250725.txt
Ran: clear;ansible-playbook   -i inventories/inventory_virtualbox.yml playbooks/site.yml --limit=gighive -vv --ask-become-pass --skip-tags blobfuse2  |& tee lastRun$(date +%Y%m%d).txt

- added bash -v to shell scripts
- added /var/log/ansible to .cfg
- changed my ansible playbook to reinclude the "lastRun*.log"
- exact changes shown below:

sodo@pop-os:~/scripts/gighive$ git status
On branch master
Your branch is up to date with 'origin/master'.

Changes not staged for commit:
  (use "git add <file>..." to update what will be committed)
  (use "git restore <file>..." to discard changes in working directory)
	modified:   .gitignore
	modified:   CHANGELOG.md
	modified:   ansible/2unregisterDeleteVm.sh
	modified:   ansible/3uuidClosemedium.sh
	modified:   ansible/ansible.cfg
	modified:   ansible/roles/cloud_init/files/seed.iso

Untracked files:
  (use "git add <file>..." to include in what will be committed)
	.github/chatmodes/test.js
	ansible/lastRun20250725.txt

***
releaseNotes20250622.txt
Wired Mailgun into your Apache/PHP container (both at build- and run-time), extended your DB setup to include and inspect a users table, and updated your Ansible roles to generate and consume a .env file for sensitive credentials. 

Here’s a high-level summary of what you’ve changed in the GigHive media library:

Ansible playbook (site.yml)
– Introduced two new variables, mailgun_api_key and mailgun_domain, alongside your existing web_root and video_dir settings. These will drive the Mailgun integration later on. 

Apache Dockerfile
– Added build-time ARGs for MAILGUN_API_KEY and MAILGUN_DOMAIN, then exposed them as container ENV so all processes can read them.
– Expanded the Composer step to pull in mailgun/mailgun-php and guzzlehttp/guzzle, then run your optimized install and reset file ownership in one go. 

PHP-FPM pool config (www.conf)
– Disabled environment clearing (clear_env = no) so Docker’s ENV vars work inside FPM.
– Passed both Mailgun credentials into FPM workers via env[...] directives. 

DB helper script (dbCommands.sh)
– Switched to using docker cp and docker exec for loading your create_music_db.sql and select.sql directly into the running MySQL container.
– Kept the legacy mysql -u appuser … dropDb.sql call for cleanup, and left a note that these commands run from the Docker host. 

Query script (select.sql)
– After listing song files, you now print a “USERS TABLE” header, count total rows in users, and show the first 10 user records. 

Database schema (create_music_db.sql)
– Added a new users table with fields for email, password hash, activation/reset tokens, login lockouts, and timestamps. 

Ansible Docker tasks (tasks/main.yml)
– Inserted a new task to render a .env file from a Jinja2 template, so your Mailgun creds (and any other vars) get baked into an /path/to/docker/.env. 

Docker-compose template (docker-compose.yml.j2)
– Passed the Mailgun args through the build context and enabled an env_file: - .env entry so your container reads those credentials at runtime. 

***
releaseNotes20250620.txt
- removed /var/www/html mount and kept it in build phase (not run) for immutability
- script to reloadMyDatabase.sh
- renamed unregisterAndDelete.sh progs to 1/2unregisterDelete
- built scripts to remove "jam_" from names from csvs, create_music_db/load_and_transform.sql, MediaController, select.sql
- removed "project_name" from docker-compose in ansible yaml

# Change the input files to reference new table names
0) drop the database:
0) remove the containers

1-3 are done using afterJamRemoval.sh 

1) run ~/scripts/stormpigsCode/9_csvprep/preprocess_for_mysql_minimalAfterDatabaseChanges.py

2) copy over the data and dependent files that have the changed db entity names to their respective directories
sodo@pop-os:~/scripts/gighive$ find . -name "*.afterJamRemoval" -print
./ansible/roles/docker/files/apache/html/src/Controllers/MediaController.php.afterJamRemoval
./ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql.afterJamRemoval
./ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql.afterJamRemoval

3) copy over the input files to prepped_csvs
sodo@pop-os:~/scripts/gighive$ ll ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/afterJamRemoval/
total 28
-rw-rw-r-- 1 sodo sodo 512 Jun 16 16:29 files.csv
-rw-rw-r-- 1 sodo sodo  95 Jun 16 16:29 musicians.csv
-rw-rw-r-- 1 sodo sodo  94 Jun 20 09:19 session_musicians.csv
-rw-rw-r-- 1 sodo sodo 341 Jun 20 09:18 sessions.csv
-rw-rw-r-- 1 sodo sodo 101 Jun 20 09:19 session_songs.csv
-rw-rw-r-- 1 sodo sodo 104 Jun 16 16:29 song_files.csv
-rw-rw-r-- 1 sodo sodo 391 Jun 16 16:29 songs.csv

4) rerun ansible to copy the files to the vm
clear;ansible-playbook   -i inventories/inventory_virtualbox.yml playbooks/site.yml --limit=gighive -vv --ask-become-pass --tags set_targets,base,docker,post_build_checks,validate_app
- ansible should rebuild the containers

5) reload the database
sodo@pop-os:~/scripts/gighive/ansible/roles/docker/files/mysql/dbScripts$ cat reloadMyDatabase.sh 
docker exec -i mysqlServer sh -c "mysql -u root -pmusiclibrary music_db < /docker-entrypoint-initdb.d/00-create_music_db.sql"
docker exec -i mysqlServer sh -c "mysql -u root -pmusiclibrary music_db < /docker-entrypoint-initdb.d/01-load_and_transform.sql"

./reloadMyDatabase.sh

TO REVERT..
Run 0-5 using the "beforeJamRemoval" files

# This is the usual ansible run script
clear;ansible-playbook   -i inventories/inventory_virtualbox.yml playbooks/site.yml --limit=gighive -vv --ask-become-pass --skip-tags blobfuse2

***
releaseNotes20250619.txt
- add api, fix upload.php, fix index.php (now in MediaController.php)

clear;ansible-playbook   -i inventories/inventory_virtualbox.yml playbooks/site.yml --limit=gighive -vv --ask-become-pass --skip-tags blobfuse2

***
releaseNotes20250616.txt
- source of the issue is that I'm mounting the NFS drive which is causing confusion
- remove the nfs mount
- try copying only the scripts and only the small amount of video files.

***
releaseNotes20250615.txt

in main:
- updated 2bootstrap to write jinja inventory template with control flow
- added jinja2/jinja2cli prereqs

in ansible:
- remove root login and password login for ubuntu user, ssh only now
- removed any old password references
- removed .bashrc aliases
- changed web_root from stormpigs to ~/script/stormpigsCode/codeGeneric/production
- reduced database to only two jams, 20021024 and 20050303
- roles/base/tasks/main.yml make sure {{ configs_dir }}/prepped_csvs is owned by azureuser and not lxd
- playbooks/site.yml change to include a role to refresh the database, this requires optional switches: 
    ansible-playbook   -i inventories/inventory_azure.yml   playbooks/site.yml --limit=gighive -vv --tags base,reload-mysql,set_targets -e mysql_reload_enabled=true
- these files go together:
        #code_home: "/home/sodo/scripts/stormpigsCode/production/"
        code_home: "/home/sodo/scripts/codeGeneric/production/"  # this is localhost
        #web_root: "/home/{{ ansible_user }}/scripts/stormpigsCode/production"
        web_root: "/home/{{ ansible_user }}/scripts/codeGeneric/production" # this will be the webroot on the destination server

# Three sources
code_home: "/home/sodo/scripts/codeGeneric/production/"  # this is localhost
~/scripts/gighive/ansible/roles/docker/templates/docker-compose.yml.j2
~/scripts/gighive/ansible/roles/base/tasks/main.yml

ALONG WITH..
sodo@pop-os:~/scripts/gighive/ansible/roles/docker/templates$ cat docker-compose.yml.j2
services:
  apacheWebServer:
    ports:
      - "443:443"
    build:
      context: ./apache
      dockerfile: Dockerfile
    image: ubuntu22.04apache-img:1.00
    container_name: apacheWebServer
    restart: unless-stopped
    dns:
      - 127.0.0.11
      - 8.8.8.8
      - 1.1.1.1
    volumes:
      - "/home/{{ ansible_user }}/scripts/codeGeneric/production/:/var/www/html"

AND from ~/scripts/gighive/ansible/roles/base/tasks/main.yml
- name: Sync production code directory to target
  delegate_to: localhost
  become: no
  ansible.builtin.synchronize:
    mode: push
    src: "{{ code_home }}"
    dest: "{{ web_root }}"

TODO: remove scripts_home or scripts_dir in favor of one another

###
# MUST use correct inventory.yml, if you rerun, include "--tags set_targets" 
###

For Azure vm:
clear;ansible-playbook   -i inventories/inventory_azure.yml   playbooks/site.yml --limit=gighive -vv --skip-tags vbox_provision,blobfuse2

For Local vm:
clear;ansible-playbook   -i inventories/inventory_virtualbox.yml playbooks/site.yml --limit=gighive -vv --ask-become-pass --skip-tags blobfuse2

# DID YOU VERIFY THAT BOTH CAN BE RUN BEFORE COMMITTING TO GIT???
***

***
releaseNotes20250614.txt
- gighive rename
- correct blobfuse2 /etc/fstab
- added README.md

###
# MUST use correct inventory.yml !!!
###

For Azure vm:
clear;ansible-playbook   -i inventories/inventory_azure.yml   playbooks/site.yml --limit=gighive -vv --skip-tags vbox_provision,blobfuse2

For Local vm:
clear;ansible-playbook   -i inventories/inventory_virtualbox.yml playbooks/site.yml --limit=gighive -vv --ask-become-pass --skip-tags blobfuse2

# DID YOU VERIFY THAT BOTH CAN BE RUN BEFORE COMMITTING TO GIT???
***

***
releaseNotes20250613.txt
Removed blobfuse role for Azure storage account / blob container integration
Added blobfuse2 role for Azure storage account / blob container integration
- blobfuse2 is optional, only if you want tight coupling
- next step is to get api first working
- next step is to automate the testing of the two inventories below

###
# MUST include correct inventory.yml !!!
###

For Azure vm:
clear;ansible-playbook   -i inventories/inventory.yml.azure   playbooks/site.yml --limit=newlibrary -vv --skip-tags vbox_provision

For Local vm:
clear;ansible-playbook   -i inventories/inventory.yml.inhouse   playbooks/site.yml --limit=newlibrary -vv --ask-become-pass --skip-tags blobfuse2

# DID YOU VERIFY THAT BOTH CAN BE RUN BEFORE COMMITTING TO GIT???
***

***
releaseNotes20250608.txt
Added blobfuse role for Azure storage account / blob container integration

For Azure vm:
clear;ansible-playbook   -i inventories/inventory.yml   playbooks/site.yml --limit=newlibrary -vv --tags blobfuse

For Local vm:
clear;ansible-playbook   -i inventories/inventory.yml   playbooks/site.yml --limit=newlibrary -vv --ask-become-pass
***

***
releaseNotes20250613.txt
Removed blobfuse role for Azure storage account / blob container integration
Added blobfuse2 role for Azure storage account / blob container integration
- blobfuse2 is optional, only if you want tight coupling


###
# MUST include correct inventory.yml !!!
###

For Azure vm:
clear;ansible-playbook   -i inventories/inventory.yml.azure   playbooks/site.yml --limit=newlibrary -vv --skip-tags vbox_provision

For Local vm:
clear;ansible-playbook   -i inventories/inventory.yml.inhouse   playbooks/site.yml --limit=newlibrary -vv --ask-become-pass --skip-tags blobfuse2

# DID YOU VERIFY THAT BOTH CAN BE RUN BEFORE COMMITTING TO GIT???
***

***
releaseNotes20250608.txt
Added blobfuse role for Azure storage account / blob container integration

For Azure vm:
clear;ansible-playbook   -i inventories/inventory.yml   playbooks/site.yml --limit=newlibrary -vv --tags blobfuse

For Local vm:
clear;ansible-playbook   -i inventories/inventory.yml   playbooks/site.yml --limit=newlibrary -vv --ask-become-pass
***

***
releaseNotes20250607.txt
Standardized inventories/group_vars/newlibrary.yml with follow on changes to playbooks/site.yml and inventories/inventory.yml*
Note new command line addition below
Added wait before json request
Merged docker install/compose into main.yml


For Azure vm:
clear;ansible-playbook   -i inventories/inventory.yml   playbooks/site.yml --limit=newlibrary -vv --skip-tags vbox_provision

For Local vm:
clear;ansible-playbook   -i inventories/inventory.yml   playbooks/site.yml --limit=newlibrary -vv --ask-become-pass
***

***
releaseNotes20250606.txt
Changed wait time in validate_app main.yml to make more solid
Created a bootstrap.sh in the terraform directory (but that's not part of this git)


For Azure vm:
clear;ansible-playbook   -i inventories/inventory.yml   playbooks/site.yml -vv --skip-tags vbox_provision

For Local vm:
clear;ansible-playbook   -i inventories/inventory.yml   playbooks/site.yml -vv --ask-become-pass
***

***
releaseNotes20250602.txt
Removed the become_user: "{{ ansible_user }}" in Bring up Docker Compose V2 stack (run as root)
Changed wait time to 30s in validate_app main.yml


For Azure vm:
clear;ansible-playbook   -i inventories/inventory.yml   playbooks/site.yml -vv --skip-tags vbox_provision

For Local vm:
clear;ansible-playbook   -i inventories/inventory.yml   playbooks/site.yml -vv --ask-become-pass
***

***
releaseNotes20250601.txt
ansible-playbook   -i inventories/inventory.yml   playbooks/site.yml -v --skip-tags vbox_provision
  --start-at-task="Bring up Docker Compose"

Removed comments from site.yml
Added static /etc/resolve.conf to base main.yml

Had to set /etc/hosts manually
azureuser@newlibrary:~$ sudo sh -c 'echo "127.0.1.1 newlibrary" >> /etc/hosts'
sudo: unable to resolve host newlibrary: Name or service not known
***

***
releaseNotes20250531failureToCreateTmp.txt
Learnings

RUN
ansible-playbook   -i inventories/inventory.yml   playbooks/site.yml   --ask-become-pass   -v --syntax-check

PROBLEM
Failure to create tmp directory
got this error..what does it mean?  PLAY [Disable Cloud-Init inside VM] **********************************************************************

TASK [Gathering Facts] ***********************************************************************************
fatal: [newlibrary]: UNREACHABLE! => {"changed": false, "msg": "Failed to create temporary directory. In some cases, you may have been able to authenticate and did not have permissions on the target directory. Consider changing the remote tmp path in ansible.cfg to a path rooted in \"/tmp\", for more error information use -vvv. Failed command was: ( umask 77 && mkdir -p \" echo /home/ubuntu/.ansible/tmp \"&& mkdir \" echo /home/ubuntu/.ansible/tmp/ansible-tmp-1748631986.3285537-356950-231363626879534 \" && echo ansible-tmp-1748631986.3285537-356950-231363626879534=\" echo /home/ubuntu/.ansible/tmp/ansible-tmp-1748631986.3285537-356950-231363626879534 \" ), exited with result 1", "unreachable": true}

CAUSE
- I believe a combination of new ansible-collections versions and popos not being rebooted may have contributed to this issue:

SOLUTION

LEARNINGS
- make sure you reboot your ansible host if you've done some sort of upgrade, otherwise you're chasing ghosts
- remove both vmdk/vdi, rebuild the whole shebang
- VBoxManage closemedium disk 1fb4baa4-3b14-4078-bfbf-abd9d70a7352
- Initial build took 20 minutes
- always log into new machine as it builds and run at least vmstat, if not top to make sure things are cooking
- make sure ssh session gets killed when you delete the vm..in my case, I noticed I was still able to log into it, a sure sign something has gone wrong
***

***
releaseNotes20250530.txt
I changed the scripts to remove ubuntu and make ansible_user configurable

sodo@pop-os:~/scripts/newlibrary$ ./checkAll.sh 
base
-rw-rw-r-- 1 sodo sodo 5726 May 30 13:53 roles/base/tasks/main.yml
      alias ubuntuserver='ssh sodo@ubuntuserver'
      alias ubuntu='ssh ubuntu@ubuntu'
      alias dev='ssh ubuntu@musiclibrary-dev'
      alias ml='ssh ubuntu@musiclibrary'

cloud_init
-rw-r--r-- 1 sodo sodo 6738 May 30 13:55 roles/cloud_init/tasks/main.yml

cloud_init_disable
-rw-rw-r-- 1 sodo sodo 1216 May 26 10:43 roles/cloud_init_disable/tasks/main.yml

docker
-rw-rw-r-- 1 sodo sodo 57 May 26 08:55 roles/docker/tasks/main.yml

nfs_mount
-rw-rw-r-- 1 sodo sodo 524 May 30 13:50 roles/nfs_mount/tasks/main.yml

post_build_checks
-rw-rw-r-- 1 sodo sodo 2463 May 27 16:58 roles/post_build_checks/tasks/main.yml

validate_app
-rw-rw-r-- 1 sodo sodo 3670 May 30 14:03 roles/validate_app/tasks/main.yml
***

***
releaseNotes20250528.txt
Release notes: 20250528
based off of ../newlibraryNoRoles

Command:
sodo@pop-os:~/scripts/newlibrary$ ansible-playbook   -i inventories/inventory.yml   playbooks/site.yml   --tags validate_app   --ask-become-pass   -v --syntax-check

sodo@pop-os:~/scripts/newlibrary$ ansible-playbook   -i inventories/inventory.yml   playbooks/site.yml   --tags validate_app   --ask-become-pass   -v

I changed 
* playbooks/site.yml (moved out vars & changed first host to newlibrary_group)
* inventories/group_vars/newlibrary_group.yml (moved vars in site.yml to group_vars)
* roles/cloud_init/tasks/main.yml (changed all the logic)
- not sure why Ensure python3-pip takes so long to install..
- ping and netcat in apache take a long time too

If I need to revert, go back to files previous to 5/28 (so 5/27)

Create and register the VM still blows up when rerun

TASK [cloud_init : Create & register VM if missing] *********************************************************************
task path: /mnt/scottsfiles/scripts/newlibrary/roles/cloud_init/tasks/main.yml:122
fatal: [newlibrary]: FAILED! => {"changed": true, "cmd": ["VBoxManage", "createvm", "--name", "newlibrary", "--ostype", "Ubuntu_64", "--register"], "delta": "0:00:00.027878", "end": "2025-05-28 22:30:14.365667", "msg": "non-zero return code", "rc": 1, "start": "2025-05-28 22:30:14.337789", "stderr": "VBoxManage: error: Machine settings file '/home/sodo/VirtualBox VMs/newlibrary/newlibrary.vbox' already exists\nVBoxManage: error: Details: code VBOX_E_FILE_ERROR (0x80bb0004), component MachineWrap, interface IMachine, callee nsISupports\nVBoxManage: error: Context: \"CreateMachine(bstrSettingsFile.raw(), bstrName.raw(), ComSafeArrayAsInParam(groups), bstrOsTypeId.raw(), createFlags.raw(), bstrCipher.raw(), bstrPasswordId.raw(), Bstr(strPassword).raw(), machine.asOutParam())\" at line 397 of file VBoxManageMisc.cpp", "stderr_lines": ["VBoxManage: error: Machine settings file '/home/sodo/VirtualBox VMs/newlibrary/newlibrary.vbox' already exists", "VBoxManage: error: Details: code VBOX_E_FILE_ERROR (0x80bb0004), component MachineWrap, interface IMachine, callee nsISupports", "VBoxManage: error: Context: \"CreateMachine(bstrSettingsFile.raw(), bstrName.raw(), ComSafeArrayAsInParam(groups), bstrOsTypeId.raw(), createFlags.raw(), bstrCipher.raw(), bstrPasswordId.raw(), Bstr(strPassword).raw(), machine.asOutParam())\" at line 397 of file VBoxManageMisc.cpp"], "stdout": "", "stdout_lines": []}

PLAY RECAP **************************************************************************************************************
newlibrary                 : ok=8    changed=1    unreachable=0    failed=1    skipped=2    rescued=0    ignored=0  
***

