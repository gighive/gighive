# Security Auth Changes for Uploader

This document describes the exact, minimal changes to introduce a third user `uploader` alongside the existing `admin` and `viewer`, using the current htpasswd + `Require` model.

No changes have been applied yet; this is the proposed patch set.

---

## 1) Inventory variables (group_vars)

File: `ansible/inventories/group_vars/gighive.yml`

Add two variables mirroring the existing `admin_user`/`viewer_user` and their passwords:

```diff
*** a/ansible/inventories/group_vars/gighive.yml
--- b/ansible/inventories/group_vars/gighive.yml
@@
 # Users
 admin_user: admin
 viewer_user: viewer
+uploader_user: uploader
@@
 # Use vault/encrypted vars in real life:
 gighive_admin_password: "secretadmin"
 gighive_viewer_password: "secretviewer"
+gighive_uploader_password: "secretuploader"
```

Notes:
- In real deployments, store `gighive_uploader_password` in Ansible Vault.

---

## 2) Role `security_basic_auth`: add uploader to htpasswd (host + container)

File: `ansible/roles/security_basic_auth/tasks/main.yml`

Add tasks that mirror the existing `admin` and `viewer` tasks for both the host-side htpasswd and the in-container htpasswd.

### 2.1 Create/Update uploader user in host htpasswd (bcrypt)
Place right after the existing "Create/Update admin user in host htpasswd (bcrypt)" and the optional viewer block (around current lines ~53–76):

```diff
*** a/ansible/roles/security_basic_auth/tasks/main.yml
--- b/ansible/roles/security_basic_auth/tasks/main.yml
@@
 - name: Optionally create/update viewer user in host htpasswd (bcrypt)
   community.general.htpasswd:
     path: "{{ gighive_htpasswd_host_path }}"
     name: "{{ viewer_user }}"
     password: "{{ gighive_viewer_password }}"
     crypt_scheme: bcrypt
     owner: "{{ gighive_htpasswd_owner | default('root') }}"
     group: "{{ gighive_htpasswd_group | default('www-data') }}"
     mode: "{{ gighive_htpasswd_mode  | default('0640') }}"
   when:
     - viewer_user is defined
     - gighive_viewer_password is defined
     - (gighive_viewer_password | length) > 0
+
+- name: Optionally create/update uploader user in host htpasswd (bcrypt)
+  community.general.htpasswd:
+    path: "{{ gighive_htpasswd_host_path }}"
+    name: "{{ uploader_user }}"
+    password: "{{ gighive_uploader_password }}"
+    crypt_scheme: bcrypt
+    owner: "{{ gighive_htpasswd_owner | default('root') }}"
+    group: "{{ gighive_htpasswd_group | default('www-data') }}"
+    mode: "{{ gighive_htpasswd_mode  | default('0640') }}"
+  when:
+    - uploader_user is defined
+    - gighive_uploader_password is defined
+    - (gighive_uploader_password | length) > 0
```

### 2.2 Ensure uploader in in-container htpasswd (bcrypt)
Place right after the existing container-side blocks that seed `admin` and ensure `viewer` (around current lines ~113–134):

```diff
*** a/ansible/roles/security_basic_auth/tasks/main.yml
--- b/ansible/roles/security_basic_auth/tasks/main.yml
@@
 - name: Ensure viewer in htpasswd (bcrypt)
   ansible.builtin.htpasswd:
     path: "{{ gighive_htpasswd_path }}"
     name: "{{ viewer_user }}"
     password: "{{ gighive_viewer_password }}"
     crypt_scheme: bcrypt
   become: true
   no_log: true
+
+- name: Ensure uploader in htpasswd (bcrypt)
+  ansible.builtin.htpasswd:
+    path: "{{ gighive_htpasswd_path }}"
+    name: "{{ uploader_user }}"
+    password: "{{ gighive_uploader_password }}"
+    crypt_scheme: bcrypt
+  become: true
+  no_log: true
```

---

## 3) Verification: htpasswd-level check for uploader

Add a verification step similar to `Verify admin (host)` and `Verify viewer (host)` (around current lines ~135–160). We will:
- Verify uploader credentials with `htpasswd -vb`.
- Add `uploader_ok` to the verification facts.

```diff
*** a/ansible/roles/security_basic_auth/tasks/main.yml
--- b/ansible/roles/security_basic_auth/tasks/main.yml
@@
 - name: Verify viewer (host)
   ansible.builtin.shell: >
     htpasswd -vb "{{ gighive_htpasswd_path }}" "{{ viewer_user }}" "{{ gighive_viewer_password }}"
   args:
     executable: /bin/bash
   register: _v_viewer_host
   changed_when: false
   failed_when: false
   no_log: true
@@
 - name: Set verification facts (host)
   ansible.builtin.set_fact:
     admin_ok: "{{ (_v_admin_host.rc | default(1)) == 0 }}"
     viewer_ok: "{{ (_v_viewer_host.rc | default(1)) == 0 }}"
   changed_when: false
+
+- name: Verify uploader (host)
+  ansible.builtin.shell: >
+    htpasswd -vb "{{ gighive_htpasswd_path }}" "{{ uploader_user }}" "{{ gighive_uploader_password }}"
+  args:
+    executable: /bin/bash
+  register: _v_uploader_host
+  changed_when: false
+  failed_when: false
+  no_log: true
+
+- name: Extend verification facts (host)
+  ansible.builtin.set_fact:
+    uploader_ok: "{{ (_v_uploader_host.rc | default(1)) == 0 }}"
+  changed_when: false
```

You may optionally extend the masked display to include the uploader hash, but avoid logging secrets.

---

## 4) HTTP probe as uploader (expect 200)

In the existing HTTP verification block (around lines ~205+), add an uploader probe mirroring admin/viewer:

```diff
*** a/ansible/roles/security_basic_auth/tasks/main.yml
--- b/ansible/roles/security_basic_auth/tasks/main.yml
@@
     - name: Probe as admin (expect 200)
       ansible.builtin.uri:
         url: "{{ gighive_auth_probe_url_eff }}"
         method: GET
         force_basic_auth: true
         url_username: "{{ admin_user }}"
         url_password: "{{ gighive_admin_password }}"
@@
     - name: Probe as viewer (expect 200)
       ansible.builtin.uri:
         url: "{{ gighive_auth_probe_url_eff }}"
         method: GET
         force_basic_auth: true
         url_username: "{{ viewer_user }}"
         url_password: "{{ gighive_viewer_password }}"
@@
+    - name: Probe as uploader (expect 200)
+      ansible.builtin.uri:
+        url: "{{ gighive_auth_probe_url_eff }}"
+        method: GET
+        force_basic_auth: true
+        url_username: "{{ uploader_user }}"
+        url_password: "{{ gighive_uploader_password }}"
+        return_content: false
+        validate_certs: "{{ (not gighive_auth_probe_insecure | default(false)) and (gighive_validate_certs | default(true)) }}"
+        status_code: [200]
+      register: _http_uploader
+      changed_when: false
+      failed_when: false
+      no_log: true
+      retries: 3
+      delay: 2
+      until: _http_uploader.status == 200
+
     - name: Set HTTP verification facts
       ansible.builtin.set_fact:
         admin_http_ok: "{{ _http_admin.status == 200 }}"
         viewer_http_ok: "{{ _http_viewer.status == 200 }}"
+        uploader_http_ok: "{{ _http_uploader.status == 200 }}"
         unauth_http_locked: "{{ _http_noauth.status in [401, 403] }}"
       changed_when: false
@@
     - name: Assert Basic Auth works end-to-end
       ansible.builtin.assert:
         that:
           - admin_http_ok
           - viewer_http_ok
+          - uploader_http_ok
           - unauth_http_locked
         fail_msg: >-
           Basic Auth probe failed:
           admin={{ _http_admin.status | default('n/a') }},
           viewer={{ _http_viewer.status | default('n/a') }},
+          uploader={{ _http_uploader.status | default('n/a') }},
           unauth={{ _http_noauth.status | default('n/a') }}
         success_msg: "Basic Auth verified via HTTP (admin/viewer/uploader OK; unauth blocked)."
@@
     - name: Show HTTP verification summary (no secrets)
       ansible.builtin.debug:
         msg:
           - "Probe URL: {{ gighive_auth_probe_url_eff }}"
           - "admin_http_ok={{ admin_http_ok }}"
           - "viewer_http_ok={{ viewer_http_ok }}"
+          - "uploader_http_ok={{ uploader_http_ok }}"
           - "unauth_http_locked={{ unauth_http_locked }}"
```

Notes:
- The probe URL (`gighive_auth_probe_url_eff`) is a read-only page today (e.g., `db/database.php`), so expecting 200 for `uploader` is correct.
- If you later want to specifically test access to the upload form/API you can add a separate probe to `/db/upload_form.php` or `/api/uploads.php` (expect 401 for viewer, 200 for admin/uploader) after the vhost changes are deployed.

---

## 5) Summary
- Add `uploader_user` and `gighive_uploader_password` to inventory.
- Create/ensure `uploader` in both host and container htpasswd (bcrypt).
- Verify `uploader` credentials via `htpasswd -vb`.
- HTTP probe as `uploader` (expect 200 on a read-only page).
- Combined with the earlier vhost changes (`Require user admin uploader` on upload routes), this completes the uploader role addition.
