# Problem: SSH Passwordless Auth Failures — Two Distinct Root Causes

---

## Issue 1 — RSA Key Split Across Two Lines in `authorized_keys`

**Date first seen:** 2026-06-06 (pop-os → stagingvm.gighive.internal)

### Symptom

`ssh -i ~/.ssh/id_rsa <host>` prompts for a password instead of authenticating
silently, even though:

- `ssh-copy-id -i ~/.ssh/id_rsa.pub <host>` reports  
  `All keys were skipped because they already exist on the remote system.`
- `ssh -i ~/.ssh/id_ed25519 <host>` works without a password.

Verbose SSH output (`-v`) shows:

```
debug1: Offering public key: /home/sodo/.ssh/id_rsa RSA SHA256:...
debug1: Authentications that can continue: publickey,password
```

The server offers `publickey,password` but does **not** print
`debug1: Server accepts key` for the RSA key — it falls through to password.

---

### Root Cause

The RSA public key stored in `/home/ubuntu/.ssh/authorized_keys` on the remote VM
is **split across two lines** mid-base64, making it unparseable by `sshd`.

Example of a malformed entry:

```
ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQDSI+y3JdgXcrECCQdiaxQ9svmCh+...sN9
dol/a8U90WV9PfhXMQK2sbBtUsGEOmQ...cugc= sodo@staging
```

`sshd` requires each key on a **single unbroken line**.  `ssh-copy-id` compares key
data (ignoring line formatting), so it incorrectly reports the key as already
present when the base64 blob matches but is wrapped.

#### Why does the split happen?

The key was likely written to `authorized_keys` by a process that respected an
80- or 120-character column width (e.g., a `printf` / `echo` inside a shell
script, a clipboard paste with terminal line-wrapping, or an older
`cloud-init` path).

#### Why does `ssh-copy-id` say "already installed"?

`ssh-copy-id` strips comments and reconstructs the key blob from lines prefixed
`ssh-rsa`, `ecdsa-*`, `ssh-ed25519`, etc. If the continuation line (the wrapped
fragment) begins without an `ssh-*` prefix, `ssh-copy-id` may piece together a
match against the local `.pub` file and conclude the key is already present.

---

### Diagnosis

```bash
# Show raw line endings; wrapped keys appear as two separate $ markers
ssh -i ~/.ssh/id_ed25519 ubuntu@<host> "cat -A ~/.ssh/authorized_keys | head -10"

# Confirm line count — should equal number of keys
ssh -i ~/.ssh/id_ed25519 ubuntu@<host> "wc -l ~/.ssh/authorized_keys"
```

A healthy file with one RSA key and one ed25519 key returns `2`.

---

### Fix

#### Step 1 — Remove the malformed RSA entry, keep the good ed25519 line

```bash
ssh -i ~/.ssh/id_ed25519 ubuntu@<host> \
  "grep '^ssh-ed25519' ~/.ssh/authorized_keys > /tmp/ak_clean \
   && mv /tmp/ak_clean ~/.ssh/authorized_keys"
```

#### Step 2 — Append the RSA key as a single line (bypass ssh-copy-id)

```bash
cat ~/.ssh/id_rsa.pub | ssh -i ~/.ssh/id_ed25519 ubuntu@<host> \
  "cat >> ~/.ssh/authorized_keys"
```

#### Step 3 — Verify

```bash
ssh -i ~/.ssh/id_rsa <host> echo ok   # should print: ok
```

---

### Prevention

- Use `ssh-copy-id` only from a known-good shell (not through a terminal with
  hard-wrap enabled).
- After any VM rebuild, verify with `cat -A ~/.ssh/authorized_keys | grep -c '\$'`
  — the count should equal the number of keys.
- The `cloud_init` `user-data.j2` template uses `{{ my_ssh_key | quote }}` which
  is safe for a single key, but any post-init additions should use the
  `cat >> authorized_keys` pattern above rather than a multi-line heredoc or
  `printf`.

---

---

## Issue 2 — Ansible Control Host RSA Key Not in Target VM's `authorized_keys`

**Date first seen:** 2026-06-06 (staging.gighive.internal → stagingvm.gighive.internal)

### Symptom

Running Ansible (or manual `ssh`) from a **different control host** (e.g.
`staging.gighive.internal`) to a VM (`stagingvm.gighive.internal`) prompts for a
password, even though the same VM accepts passwordless connections from pop-os.

Verbose output from the control host shows the RSA key is offered but rejected:

```
debug1: Offering public key: /home/sodo/.ssh/id_rsa RSA SHA256:hnbb4UDbZ5f...
debug1: Authentications that can continue: publickey,password
```

Note: the RSA fingerprint on the control host (`SHA256:hnbb4UDbZ5f...`) is
**different** from the one on pop-os (`SHA256:B8GpgdCq48...`) — each machine has
its own key pair.

### Root Cause

Each machine has its own `~/.ssh/id_rsa`. Only the **pop-os** RSA key was added
to `stagingvm`'s `authorized_keys` during initial setup. The control host
(`staging.gighive.internal`) has a separate RSA key that was never added.

Additionally, `staging.gighive.internal` had no `id_ed25519` (type -1 in verbose
output), so there was no fallback key that happened to be present.

### Diagnosis

```bash
# Check what keys are in stagingvm's authorized_keys
ssh ubuntu@stagingvm.gighive.internal "cat -A ~/.ssh/authorized_keys"

# Get fingerprint of the control host's RSA key
ssh sodo@staging.gighive.internal "ssh-keygen -lf ~/.ssh/id_rsa.pub"

# Compare: is that fingerprint in stagingvm's authorized_keys?
ssh ubuntu@stagingvm.gighive.internal "ssh-keygen -lf ~/.ssh/authorized_keys"
```

If the control host's fingerprint is absent from `authorized_keys`, the key is missing.

### Fix

From pop-os (which has passwordless access to both machines), bridge the key addition
in one command:

```bash
ssh sodo@staging.gighive.internal "cat ~/.ssh/id_rsa.pub" \
  | ssh ubuntu@stagingvm.gighive.internal "cat >> ~/.ssh/authorized_keys"
```

Verify:

```bash
ssh sodo@staging.gighive.internal "ssh ubuntu@stagingvm.gighive.internal echo ok"
# Expected: ok
```

### Prevention

- When provisioning a new VM, add **all** control host RSA keys that will run
  Ansible against it — not just pop-os's key.
- The `cloud_init` `user-data.j2` template accepts a `ssh_authorized_keys` list;
  extend it if multiple control hosts need access.
- After any new VM is built, test `ssh ubuntu@<vm> echo ok` from **every host**
  that will run Ansible against it before attempting a playbook run.

---

## Related Files

- `ansible/roles/cloud_init/templates/user-data.j2` — initial key injection via
  `ssh_authorized_keys`
- `~/.ssh/config` — SSH host aliases (`lab`, `dev`, `staging`)
- `~/.codeium/windsurf/mcp_config.json` — MCP server SSH invocations that depend
  on passwordless RSA auth
