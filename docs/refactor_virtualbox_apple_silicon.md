# Refactor: VirtualBox VM Bootstrap — Apple Silicon (ARM64) Support

## Status — 2026-09-01

Planning. No changes made yet. Root cause of incorrect image reference confirmed by direct inspection of Ubuntu cloud image index. Broader macOS-compatibility gaps in the `cloud_init` role identified and documented below.

---

## Elevator Pitch

Right now, the scripts that spin up the GigHive development virtual machine are hard-wired to download an Intel-format disk image from Ubuntu. A MacBook with Apple Silicon uses a completely different CPU architecture, and Ubuntu does not publish that image format for ARM chips at all — so the download would simply fail. This refactor fixes the image reference, adapts the conversion step to what Ubuntu actually provides for ARM, and patches a handful of Linux-only commands in the playbook so the whole thing runs cleanly from a Mac instead of requiring the pop-os tower to be on.

---

## Rationale

The user is consolidating the GigHive development environment onto a MacBook Air/Pro (Apple Silicon, ARM64) and intends to retire the pop-os Intel tower. Goals stated:

- Eliminate operational overhead of maintaining a large Linux tower.
- Reduce electricity consumption by switching to Apple Silicon's dramatically lower TDP.
- Simplify the topology: one machine for both iOS/Xcode work and infrastructure playbooks.

This refactor documents the changes needed to the `cloud_init` Ansible role and its supporting `group_vars` to support that goal, and evaluates whether the goal itself is sound.

The root technical finding that drives the entire plan: Ubuntu does not publish an arm64 VMDK. The existing workflow downloads `noble-server-cloudimg-amd64.vmdk` and converts it to VDI via `VBoxManage clonemedium`, but `VBoxManage clonemedium` cannot process QCOW2 format. The arm64 image Ubuntu does publish is `noble-server-cloudimg-arm64.img`, which is QCOW2. This means the arm64 path requires a fundamentally different pipeline: download the `.img` file, convert it from QCOW2 to VDI using `qemu-img convert`, and then proceed with VirtualBox as normal. Changing the architecture string in the URL alone is not sufficient — the conversion tool must also change.

---

## Goal

Make the `cloud_init` role capable of provisioning a VirtualBox Ubuntu ARM64 VM from a Mac (Apple Silicon) control machine, while keeping the existing Intel Linux path intact for lab, staging, and prod hosts that still run on pop-os or mini-PCs.

**Policy:** No architecture should be hardcoded. The control machine CPU must be detected at runtime and drive image selection, download URL, and conversion method automatically.

---

## Is This a Good Idea? Advisory Assessment

### TL;DR

- **Yes — the power and operational benefits are real and justify the effort.**
- The honest caveat is VirtualBox on Apple Silicon: viable but not fully mature; UTM is the ready fallback.
- The scope of this consolidation is broader than just updating an image URL — retiring pop-os also moves the Ansible control machine and the repo off NFS, which requires separate action documented in Topology Change Notes below.

### Power and cost savings — real and significant

Apple M-series chips are among the most power-efficient processors available. A MacBook at full developer workload typically draws 20–40 W. A large Intel tower (Core i7/i9, discrete GPU, multiple spinning disks) typically draws 150–400 W at similar load. Running the tower 24/7 at even a conservative 200 W average costs roughly 1,752 kWh/year. At $0.15/kWh that is approximately $263/year in electricity — before factoring in cooling load in summer. The MacBook at 30 W average costs about $39/year. The savings are real, not marginal.

### Operational simplicity — yes, with a topology caveat

Per `SKILL.md`, the current topology is:
- Ansible playbooks run **from pop-os** under the `sodo` user.
- `gighiveinfra` lives on pop-os and is **NFS-mounted** into the Mac at `/Users/sodo/gighiveapp/gighiveinfra/`.

Retiring pop-os requires more than updating a VMDK URL. The repo residency and the Ansible control machine both move. `SKILL.md` must be updated to reflect the new topology, and Ansible must be installed on the Mac (via `brew install ansible` or a virtual environment). This is not complicated, but it is a prerequisite that is broader than the cloud image change.

### Apple Silicon as an Ansible control machine — well supported

Ansible runs natively on macOS ARM64. All required Ansible collections (`community.general`, `community.docker`) support macOS. `qemu-img` (needed for image conversion — see Findings below) is available via `brew install qemu`. The primary friction point is the `cloud_init` role itself, not Ansible.

### VirtualBox on Apple Silicon — the honest caveat

VirtualBox 7.x gained Apple Silicon (ARM64 host) support, but it is younger and less mature than its x86 counterpart. Known limitations as of mid-2026:

| Concern | Reality |
|---|---|
| ARM64 guest only | You cannot run an amd64 guest at native speed on Apple Silicon. Emulation exists but is very slow. The Ubuntu VM **must** be ARM64. |
| Bridged networking | VirtualBox bridged networking on macOS has historically been less reliable than on Linux. The current playbook uses bridged mode — expect some iteration on NIC detection. |
| Guest additions | VirtualBox Guest Additions for ARM64 Ubuntu may require manual build from source. |
| Maturity | VirtualBox 7.x ARM64 support is production-usable but not as battle-tested as UTM or VMware Fusion on the same hardware. |

**Alternative worth knowing:** [UTM](https://mac.getutm.app/) (free, open source) uses Apple's native Hypervisor framework and QEMU under the hood. It is generally faster and more reliable on Apple Silicon than VirtualBox and supports cloud-init via the `cloud-init` image. If VirtualBox proves problematic, UTM is the recommended fallback. VMware Fusion Pro is also free for personal use and has excellent Apple Silicon support. Neither alternative changes the Ubuntu image selection problem documented below — the arm64 image and conversion issues are the same regardless of hypervisor.

### Other benefits of the consolidation

1. **Single machine Xcode + infra workflow.** iOS builds and Ansible runs share the same terminal, no SSH back to the tower to kick off a playbook.
2. **Portability.** The entire development environment travels with the laptop.
3. **Reduced network attack surface.** One fewer always-on machine with open SSH ports on the LAN.
4. **macOS-native tooling.** Devin CLI, Windsurf, and other developer tools already run on the Mac; pop-os was running parallel instances.
5. **Quieter workspace.** A tower with fans and spinning drives is meaningfully louder than a MacBook.

### Verdict

**Yes, this is a good idea.** The power savings alone justify it within a year. The topology change is manageable. The VirtualBox ARM64 path is viable but has rough edges — be prepared to spend a session debugging NIC bridging and possibly compiling Guest Additions. If VirtualBox proves too painful, UTM is a drop-in substitute that requires no changes to cloud-init logic, only hypervisor tooling.

---

## Findings

### 1. Ubuntu does not publish an arm64 VMDK

Confirmed by direct inspection of `https://cloud-images.ubuntu.com/noble/current/` on 2026-09-01.

| File format | amd64 | arm64 |
|---|---|---|
| `.vmdk` | ✅ `noble-server-cloudimg-amd64.vmdk` | ❌ Does not exist |
| `.img` (QCOW2) | ✅ | ✅ `noble-server-cloudimg-arm64.img` |
| `.tar.gz` | ✅ | ✅ |
| `-azure.vhd.tar.gz` | ✅ | ✅ |
| `.ova` (VMware/VirtualBox) | ✅ | ❌ Does not exist |

The current `cloud_image_url` downloads `noble-server-cloudimg-amd64.vmdk`, which works on Intel. On Apple Silicon this URL must change to `noble-server-cloudimg-arm64.img` — but more importantly, the conversion step must also change because QCOW2 is not the same as VMDK.

### 2. The amd64 filename is hardcoded in three group_vars files identically

All three files contain identical copies of lines 38–42:

```
# group_vars/gighive2/gighive2.yml  (lines 38–42)
# group_vars/gighive/gighive.yml    (lines 38–42)
# group_vars/prod/prod.yml          (lines 38–42)

cloud_image_url:  "https://cloud-images.ubuntu.com/&#123;&#123; ubuntu_codename &#125;&#125;/current/&#123;&#123; ubuntu_codename &#125;&#125;-server-cloudimg-amd64.vmdk"
cloud_image_vmdk: "&#123;&#123; cloud_init_files_dir &#125;&#125;/&#123;&#123; ubuntu_codename &#125;&#125;-server-cloudimg-amd64-&#123;&#123; vm_name &#125;&#125;.vmdk"
cloud_image_vdi:  "&#123;&#123; cloud_init_files_dir &#125;&#125;/&#123;&#123; ubuntu_codename &#125;&#125;-server-cloudimg-amd64-&#123;&#123; vm_name &#125;&#125;.vdi"
```

`amd64` appears literally in the URL, the VMDK filename, and the VDI filename. None of these will produce a working download on ARM64.

### 3. The cloud_init conversion step assumes VMDK input — in three task files

`roles/cloud_init/tasks/main.yml`, `nat.yml`, and `test.yml` all contain the same stat/download/convert block:

```
- name: Convert VMDK to VDI
  command: >
    VBoxManage clonemedium disk "&#123;&#123; cloud_image_vmdk &#125;&#125;"
    "&#123;&#123; cloud_image_vdi &#125;&#125;" --format VDI
```

`VBoxManage clonemedium` understands VMDK but not QCOW2. The arm64 `.img` download is QCOW2 format (confirmed by Ubuntu's label "QCow2 UEFI/GPT Bootable disk image"). This step requires a different tool (`qemu-img`) for the arm64 path. All three task files must be updated — not just `main.yml`.

### 4. Several tasks in cloud_init are Linux-specific and will fail on macOS

| Task | Issue |
|---|---|
| KVM module check (`lsmod`) | `lsmod` does not exist on macOS |
| Default NIC detection (`ip route get 1.1.1.1`) | `ip` command does not exist on macOS; equivalent is `route get default` |
| Prereq package install (`genisoimage`, `cloud-image-utils`) | These are Linux package names; macOS equivalents are `brew install cdrtools` (provides `mkisofs`) and `cloud-image-utils` is not available in Homebrew |
| `qemu-utils` (needed for arm64 conversion) | Must be installed; on macOS: `brew install qemu` |

### 5. VirtualBox ostype for ARM64 guests

The `createvm` call uses `--ostype Ubuntu_64`. On Apple Silicon VirtualBox hosts, ARM64 guests may require a different ostype. The correct value must be verified empirically by running `VBoxManage list ostypes | grep -i ubuntu` on the Mac before assuming `Ubuntu_arm64` or any other string.

### 6. NFS mount topology will change

The current workflow runs Ansible from pop-os, where `~/gighive/` is the authoritative repo root. The Mac sees this via NFS at `/Users/sodo/gighiveapp/gighiveinfra/`. When pop-os is retired the NFS mount goes away — the repo must live natively on the Mac and `repo_root` resolution changes accordingly.

---

## Current State

Three `group_vars` files hard-code the amd64 architecture in the cloud image URL and filenames. The `cloud_init` role (`main.yml`, `nat.yml`, and `test.yml`) was written for a Linux control machine (pop-os) and contains Linux-specific commands that will fail on macOS. No architecture detection exists anywhere in the role.

---

## Proposed Implementation

### Step index

- [ ] **1.** Add `cloud_arch: "amd64"` default to `cloud_init/defaults/main.yml`
- [ ] **2.** Update group_vars (all 3 files) — add `cloud_image_ext`, rename `cloud_image_vmdk` → `cloud_image_src`, parameterize `cloud_image_url` and `cloud_image_vdi` with `cloud_arch`
- [ ] **3.** Add architecture detection block (detect, set_fact, assert, debug) near the top of `main.yml`, `nat.yml`, and `test.yml`, before the MEDIA PREP section
- [ ] **4.** Rename `cloud_image_vmdk` → `cloud_image_src` in the stat and download tasks in `main.yml`, `nat.yml`, and `test.yml`
- [ ] **5.** Replace `Convert VMDK to VDI` with an arch-branched pair — `VBoxManage clonemedium` for amd64, `qemu-img convert` for arm64 — in `main.yml`, `nat.yml`, and `test.yml`
- [ ] **6.** Guard KVM module check with `when: ansible_system == 'Linux'` in `main.yml`
- [ ] **7.** Branch default NIC detection for Linux vs macOS in `main.yml` and `test.yml`
- [ ] **8.** Split prereq package install into Linux (`package` module) and macOS (`community.general.homebrew`) branches in `main.yml`, `nat.yml`, and `test.yml`
- [ ] **9.** Verify ARM64 VirtualBox ostype string; branch `createvm --ostype` for arm64 vs amd64 in `main.yml`
- [ ] **10.** Add T-CI-1 and T-CI-2 to `cloud_init/tasks/main.yml` — see `## Tests` section below
- [ ] **11.** Branch `Generate NoCloud ISO` command for Linux (`genisoimage`) vs macOS (`mkisofs`) in `main.yml`, `nat.yml`, and `test.yml`
- [ ] **12.** Full macOS NIC resolution for `nat.yml` — branch four Linux-only detection commands and update four downstream normalizations

---

### Step 1 — Add `cloud_arch` default to `cloud_init/defaults/main.yml`

Add a single variable to `roles/cloud_init/defaults/main.yml`:

{% raw %}
```yaml
cloud_arch: "amd64"
```
{% endraw %}

Without this default, any evaluation of `cloud_image_url`, `cloud_image_src`, or `cloud_image_vdi` outside the `cloud_init` role context will throw an undefined variable error. The `set_fact` task in Step 3 overrides this at runtime.

### Step 2 — group_vars changes (all three files)

Replace the hardcoded `amd64` arch string with `cloud_arch` in the URL and filenames. Also split the URL extension: `amd64` gets `.vmdk`, `arm64` gets `.img`, since Ubuntu publishes different formats per architecture.

The variable `cloud_image_vmdk` is renamed to `cloud_image_src` to reflect that the source format is not always VMDK. **This is a breaking rename** — all references to `cloud_image_vmdk` across `main.yml`, `nat.yml`, and `test.yml` must be updated in the same change window (Steps 4 and 5).

A new `cloud_image_ext` variable centralises the format-selection ternary, which would otherwise be duplicated identically in both `cloud_image_url` and `cloud_image_src`:

{% raw %}
```yaml
cloud_image_ext: "{{ '.vmdk' if cloud_arch == 'amd64' else '.img' }}"
cloud_image_url: "https://cloud-images.ubuntu.com/{{ ubuntu_codename }}/current/{{ ubuntu_codename }}-server-cloudimg-{{ cloud_arch }}{{ cloud_image_ext }}"
cloud_image_dir: "{{ cloud_init_files_dir }}"
cloud_image_src: "{{ cloud_init_files_dir }}/{{ ubuntu_codename }}-server-cloudimg-{{ cloud_arch }}-{{ vm_name }}{{ cloud_image_ext }}"
cloud_image_vdi: "{{ cloud_init_files_dir }}/{{ ubuntu_codename }}-server-cloudimg-{{ cloud_arch }}-{{ vm_name }}.vdi"
nocloud_iso: "{{ cloud_init_files_dir }}/seed-{{ vm_name }}.iso"
```
{% endraw %}

### Step 3 — Architecture detection block (main.yml, nat.yml, test.yml)

Place these four tasks near the top of each file, before the MEDIA PREP section. In `main.yml`, place after the connection assertion. In `nat.yml` and `test.yml`, place immediately after the prereq install task.

**Important: do not use `run_once: true` on either task.** The `cloud_init` play targets `gighive:gighive2`, which may include two hosts in one run. With `run_once`, `ctrl_uname` is registered only on the first host; the second host's `set_fact` then references an undefined variable and fails, or silently falls back to the `amd64` default and downloads the wrong image. Since all hosts use `connection: local`, running `uname -m` once per host simply calls the same localhost command twice — harmless and correct.

{% raw %}
```yaml
- name: Detect control machine architecture
  delegate_to: localhost
  command: uname -m
  register: ctrl_uname
  changed_when: false

- name: Set cloud_arch fact
  set_fact:
    cloud_arch: "{{ 'arm64' if ctrl_uname.stdout | trim == 'aarch64' else 'amd64' }}"

- name: Assert cloud_arch is a known value
  assert:
    that: cloud_arch in ['amd64', 'arm64']
    fail_msg: "Unexpected architecture detected: {{ ctrl_uname.stdout | trim }}. Expected x86_64 or aarch64."

- name: Show detected architecture
  debug:
    msg: "Control machine architecture detected: {{ ctrl_uname.stdout | trim }} → cloud_arch={{ cloud_arch }}"
```
{% endraw %}

`uname -m` returns `aarch64` on Apple Silicon (both macOS and Linux ARM64) and `x86_64` on Intel. The `assert` and `debug` tasks surface a silent failure mode: if detection were to produce an unexpected value, the amd64 default would silently produce an image that downloads successfully but causes the VM to kernel-panic at boot, with no error until the SSH timeout fires 5 minutes later.

**`gather_facts` dependency:** The `ansible_system` guards in Steps 6–8 rely on gather_facts being enabled. The `cloud_init` play in `site.yml` does not set `gather_facts` explicitly; `ansible.cfg` does not disable it, so it defaults to `true` and `ansible_system` is available. If `gather_facts: false` is ever added to that play, the OS guards will break silently — they would need to be replaced with `uname -s` command checks.

### Step 4 — Rename `cloud_image_vmdk` → `cloud_image_src` in stat/download tasks (main.yml, nat.yml, test.yml)

The stat and download tasks in all three files reference `cloud_image_vmdk` by name. Rename every occurrence to `cloud_image_src`. No structural change to the task logic is needed — the variable value already encodes the correct file extension per architecture via `cloud_image_ext`.

### Step 5 — Branch conversion step for amd64/arm64 (main.yml, nat.yml, test.yml)

Replace the single `Convert VMDK to VDI` task in each file with an architecture-branched pair:

{% raw %}
```yaml
- name: Convert VMDK to VDI (amd64 path)
  delegate_to: localhost
  become: false
  command: >
    VBoxManage clonemedium disk "{{ cloud_image_src }}"
    "{{ cloud_image_vdi }}" --format VDI
  args:
    creates: "{{ cloud_image_vdi }}"
  when: cloud_arch == 'amd64'

- name: Convert QCOW2 img to VDI (arm64 path)
  delegate_to: localhost
  become: false
  command: >
    qemu-img convert -f qcow2 -O vdi
    "{{ cloud_image_src }}" "{{ cloud_image_vdi }}"
  args:
    creates: "{{ cloud_image_vdi }}"
  when: cloud_arch == 'arm64'
```
{% endraw %}

`qemu-img convert -O vdi` produces a native VDI directly from QCOW2. No intermediate raw file is needed.

### Step 6 — Guard KVM module check for Linux only (main.yml)

{% raw %}
```yaml
- name: Check for loaded KVM modules (can block VirtualBox)
  when: ansible_system == 'Linux'
  run_once: true
  ...
```
{% endraw %}

On macOS this check is irrelevant — KVM does not exist.

### Step 7 — Branch NIC detection for Linux/macOS (`main.yml` and `test.yml`)

{% raw %}
```yaml
- name: Detect host default route interface (Linux)
  when: ansible_system == 'Linux'
  command:
    argv: [bash, -lc, "ip route get 1.1.1.1 | awk '{for(i=1;i<=NF;i++) if ($i==\"dev\"){print $(i+1); exit}}'"]
  register: default_iface_linux
  changed_when: false
  failed_when: false

- name: Detect host default route interface (macOS)
  when: ansible_system == 'Darwin'
  command:
    argv: [bash, -lc, "route get default | awk '/interface:/{print $2}'"]
  register: default_iface_mac
  changed_when: false
  failed_when: false

- name: Normalize default iface
  set_fact:
    bridge_iface_resolved: >-
      {{ ((default_iface_linux.stdout | default(''))
          if ansible_system == 'Linux'
          else (default_iface_mac.stdout | default(''))) | trim }}
```
{% endraw %}

Note: when a task is skipped, its registered variable has no `stdout` key. The `| default('')` filter handles this correctly.

`test.yml` has an identical `ip route get 1.1.1.1` task at line 86. Apply the same three-task replacement shown above to `test.yml` verbatim — no structural difference between the two files at this step.

`nat.yml` also contains `ip route get 1.1.1.1` (line 160) but its NIC resolution block is substantially more complex; it is addressed separately in Step 12.

### Step 8 — Split prereq package install by OS (main.yml, nat.yml, test.yml)

{% raw %}
```yaml
- name: Ensure ISO tools present on control (Linux)
  become: yes
  delegate_to: localhost
  when: ansible_system == 'Linux'
  package:
    name:
      - genisoimage
      - cloud-image-utils
      - qemu-utils      # provides qemu-img for arm64 path
    state: present

- name: Ensure ISO tools present on control (macOS)
  delegate_to: localhost
  when: ansible_system == 'Darwin'
  community.general.homebrew:
    name:
      - cdrtools        # provides mkisofs (equivalent to genisoimage)
      - qemu            # provides qemu-img
    state: present
```
{% endraw %}

`cloud-image-utils` (`cloud-localds`) is not in Homebrew. On macOS, the nocloud ISO is built with `mkisofs` (from `cdrtools`). The ISO generation tasks in all three files call `genisoimage` by name; on macOS that binary does not exist. Step 11 adds the OS-branched `Generate NoCloud ISO` tasks.

Note: `cloud-image-utils` (which provides `cloud-localds`) does not appear to be called in any task file — the nocloud ISO is built directly via `genisoimage`/`mkisofs`. The package is included in the Linux prereq list for historical reasons; verify before removing, but it may be vestigial.

### Step 9 — Branch `createvm --ostype` for ARM64 (main.yml)

Verify `VBoxManage list ostypes | grep -i ubuntu` on the Mac before implementation to confirm the correct value. If it differs from `Ubuntu_64`, the `createvm` task must branch:

{% raw %}
```yaml
- name: Create & register VM if missing
  command: >
    VBoxManage createvm --name "{{ vm_name }}"
    --ostype {{ 'Ubuntu_arm64' if cloud_arch == 'arm64' else 'Ubuntu_64' }} --register
  when: not vm_exists and not vbox_file.stat.exists
```
{% endraw %}

The exact ostype string must be confirmed empirically before hardcoding it. `Ubuntu_arm64` is an assumption.

---

## Step 10 — Tests

All three tests live in `cloud_init/tasks/main.yml`, alongside the existing `# ========== VALIDATION ==========` section. This is the correct home: the role already contains `assert` tasks that validate the seed files; these tests extend that pattern by validating the conversion output and the provisioned guest. `post_build_checks` is the wrong lifecycle stage — it runs after the full Docker stack is deployed, which has no bearing on image conversion or guest architecture. T-CI-1 is specific to `main.yml` (static IP is known via `static_ip`); it does not apply to `nat.yml` where the guest IP may be DHCP-assigned and unknown post-boot.

The T-CI-* namespace is scoped to the `cloud_init` role. These numbers are independent of the flat T-N sequence used in `post_build_checks`.

| Test | Where | What it validates |
|---|---|---|
| T-CI-1 | `main.yml` — after `Wait for SSH availability` | SSH into the VM and assert `uname -m` returns `aarch64` on an arm64 control machine and `x86_64` on an amd64 control machine. Proves the correct guest image was used. |
| T-CI-2 | `main.yml` — after the conversion block (Step 5) | Assert the VDI file exists at `cloud_image_vdi` and its size is greater than 1 GB. Proves the conversion completed and was not truncated. |
| T-CI-3 | `main.yml` — inline assert in arch detection block (Step 3) | Assert `cloud_arch` matches the output of `uname -m` on the control machine. Proves the detection fact is set correctly before any downstream task uses it. |

T-CI-3 is already implemented by the `Assert cloud_arch is a known value` task added in Step 3 — no additional work required. T-CI-2 is added immediately after the conversion tasks (before `# ========== VM LIFECYCLE ==========`). T-CI-1 is added after `Wait for SSH availability` at the end of `main.yml`.

---

### Step 11 — Branch `Generate NoCloud ISO` command for Linux/macOS (`main.yml`, `nat.yml`, `test.yml`)

Step 8 installs the correct ISO tool on each OS (`genisoimage` on Linux, `mkisofs` via `cdrtools` on macOS), but the ISO generation task in all three files calls `genisoimage` by name. On macOS, `genisoimage` does not exist — the playbook will fail at this step regardless of the earlier architecture detection. Replace the single `Generate NoCloud ISO` task in each file with an OS-branched pair:

{% raw %}
```yaml
- name: Generate NoCloud ISO (Linux)
  become: yes
  when: ansible_system == 'Linux'
  command: >
    genisoimage -output "{{ nocloud_iso }}"
    -volid CIDATA -joliet-long -rock
    "{{ cloud_image_dir }}/user-data"
    "{{ cloud_image_dir }}/meta-data"
    "{{ cloud_image_dir }}/network-config"

- name: Generate NoCloud ISO (macOS)
  when: ansible_system == 'Darwin'
  command: >
    mkisofs -output "{{ nocloud_iso }}"
    -volid CIDATA -joliet-long -rock
    "{{ cloud_image_dir }}/user-data"
    "{{ cloud_image_dir }}/meta-data"
    "{{ cloud_image_dir }}/network-config"
```
{% endraw %}

`become: yes` is omitted from the macOS branch — `mkisofs` does not require root on macOS and Ansible's sudo escalation would prompt for a password. On Linux the existing `become: yes` is preserved since the `nocloud_iso` destination directory may require elevated write access.

---

### Step 12 — Full macOS NIC resolution for `nat.yml`

`nat.yml`'s NIC resolution block (`NETWORK ADAPTER RESOLUTION`, lines 152–281) contains four Linux-specific commands. The surrounding Jinja logic — subnet matching, bridge/NAT decision, `eno1` preference — requires no changes; it operates on facts fed to it and works correctly once those facts are populated by the right OS-specific tool. Eight changes in total: four detection tasks are each split into a Linux and a macOS variant; four downstream `set_fact` normalizations are updated to select the correct registered variable by `ansible_system`.

**Change 1 of 8 — Branch `Detect host default route interface`**

Replace the single task with a Linux/macOS pair. Update `Normalize bridged IF list` to resolve `default_iface_trim` from the correct register.

{% raw %}
```yaml
- name: Detect host default route interface (Linux)
  when: ansible_system == 'Linux'
  delegate_to: localhost
  command:
    argv:
      - bash
      - -lc
      - |
          ip route get 1.1.1.1 | awk '{for(i=1;i<=NF;i++) if ($i=="dev"){print $(i+1); exit}}'
  register: default_iface_linux
  changed_when: false
  failed_when: false

- name: Detect host default route interface (macOS)
  when: ansible_system == 'Darwin'
  delegate_to: localhost
  command:
    argv: [bash, -lc, "route get default | awk '/interface:/{print $2}'"]
  register: default_iface_mac
  changed_when: false
  failed_when: false

# --- update existing Normalize bridged IF list ---
- name: Normalize bridged IF list
  set_fact:
    bridged_ifs_trimmed: "{{ (bridged_ifs.stdout_lines | default([])) | map('trim') | list }}"
    default_iface_trim: >-
      {{ ((default_iface_linux.stdout | default(''))
          if ansible_system == 'Linux'
          else (default_iface_mac.stdout | default(''))) | trim }}
```
{% endraw %}

**Change 2 of 8 — Branch `List host interface names` (`ip -o link`)**

Replace with a Linux/macOS pair. Update `Normalize host interface names`.

{% raw %}
```yaml
- name: List host interface names (Linux)
  when: ansible_system == 'Linux'
  delegate_to: localhost
  command:
    argv:
      - bash
      - -lc
      - ip -o link | awk -F': ' '{print $2}'
  register: host_ifnames_linux
  changed_when: false
  failed_when: false

- name: List host interface names (macOS)
  when: ansible_system == 'Darwin'
  delegate_to: localhost
  command:
    argv: [bash, -lc, "ifconfig -l | tr ' ' '\\n' | grep -v '^$'"]
  register: host_ifnames_mac
  changed_when: false
  failed_when: false

# --- update existing Normalize host interface names ---
- name: Normalize host interface names
  set_fact:
    host_ifnames_trimmed: >-
      {{ ((host_ifnames_linux.stdout_lines | default([]))
          if ansible_system == 'Linux'
          else (host_ifnames_mac.stdout_lines | default([]))) | map('trim') | list }}
```
{% endraw %}

**Change 3 of 8 — Branch `Build set of bridged IFs that are UP on host` (`/sys/class/net`)**

`/sys/class/net/${n}/operstate` is a Linux-only proc filesystem path. macOS equivalent: `ifconfig "$n" | grep -q 'status: active'`. Update `Final valid bridged IFs`.

{% raw %}
```yaml
- name: Build set of bridged IFs that are UP on host (Linux)
  when: ansible_system == 'Linux'
  delegate_to: localhost
  shell: |
    set -euo pipefail
    for n in {{ (bridged_ifs_existing | map('quote') | join(' ')) | default('', true) }}; do
      st="/sys/class/net/${n}/operstate"
      if [ -r "$st" ] && [ "$(cat "$st" 2>/dev/null)" = "up" ]; then
        echo "$n"
      fi
    done
  args:
    executable: /bin/bash
  register: bridged_ifs_up_linux
  changed_when: false
  failed_when: false

- name: Build set of bridged IFs that are UP on host (macOS)
  when: ansible_system == 'Darwin'
  delegate_to: localhost
  shell: |
    set -euo pipefail
    for n in {{ (bridged_ifs_existing | map('quote') | join(' ')) | default('', true) }}; do
      if ifconfig "$n" 2>/dev/null | grep -q 'status: active'; then
        echo "$n"
      fi
    done
  args:
    executable: /bin/bash
  register: bridged_ifs_up_mac
  changed_when: false
  failed_when: false

# --- update existing Final valid bridged IFs ---
- name: Final valid bridged IFs (VB exposed ∩ host exists ∩ state=up)
  set_fact:
    bridged_ifs_valid: >-
      {{ ((bridged_ifs_up_linux.stdout_lines | default([]))
          if ansible_system == 'Linux'
          else (bridged_ifs_up_mac.stdout_lines | default([]))) | map('trim') | list }}
```
{% endraw %}

**Change 4 of 8 — Branch `Compute host IPv4 for each valid bridged IF` (`ip -o -4 addr show`)**

macOS equivalent: `ipconfig getifaddr "$n"` (returns the IP only; `/24` is appended to produce a CIDR that feeds the existing first-3-octet subnet-matching Jinja). Update `Accumulate host IF -> CIDR map`.

{% raw %}
```yaml
- name: Compute host IPv4 for each valid bridged IF (Linux)
  when: ansible_system == 'Linux'
  delegate_to: localhost
  shell: |
    set -euo pipefail
    for n in {{ (bridged_ifs_valid | map('quote') | join(' ')) | default('', true) }}; do
      ip -o -4 addr show dev "$n" | awk '{print $4}' | head -n1 | awk -v ifn="$n" 'NF{print ifn" "$0}'
    done
  args:
    executable: /bin/bash
  register: host_if_ipv4s_linux
  changed_when: false
  failed_when: false

- name: Compute host IPv4 for each valid bridged IF (macOS)
  when: ansible_system == 'Darwin'
  delegate_to: localhost
  shell: |
    set -euo pipefail
    for n in {{ (bridged_ifs_valid | map('quote') | join(' ')) | default('', true) }}; do
      ip=$(ipconfig getifaddr "$n" 2>/dev/null || true)
      [ -n "$ip" ] && echo "$n ${ip}/24"
    done
  args:
    executable: /bin/bash
  register: host_if_ipv4s_mac
  changed_when: false
  failed_when: false

# --- update existing Accumulate host IF -> CIDR map ---
- name: Accumulate host IF -> CIDR map
  set_fact:
    host_if_cidrs: "{{ host_if_cidrs | combine( { (item.split(' ')[0]): (item.split(' ')[1]) } ) }}"
  loop: >-
    {{ ((host_if_ipv4s_linux.stdout_lines | default([]))
        if ansible_system == 'Linux'
        else (host_if_ipv4s_mac.stdout_lines | default([]))) | default([]) }}
  when: (item | length) > 0
```
{% endraw %}

All remaining tasks in the block — `Build set of bridged IFs that exist on host`, `Compute guest CIDR`, `Compute guest / host network match`, `Resolve bridge_iface_resolved`, `Flag NAT fallback` — consume `bridged_ifs_trimmed`, `host_ifnames_trimmed`, `bridged_ifs_valid`, `host_if_cidrs`, and `default_iface_trim`. These facts are now correctly populated on both platforms. No further changes to those tasks.

The `Resolve bridge_iface_resolved` task contains a hardcoded `eno1` preference (pop-os specific). This is harmless on macOS — the check never matches, and resolution falls through to `default_iface_trim` or the first valid subnet-matching interface as intended.

---

## Files Under Change

### Modified

1. `ansible/inventories/group_vars/gighive2/gighive2.yml` — `gighiveinfra` repo — replace hardcoded `amd64`; rename `cloud_image_vmdk` → `cloud_image_src`; add `cloud_image_ext`; update `cloud_image_url` and `cloud_image_vdi` to reference `cloud_arch` and `cloud_image_ext`.
2. `ansible/inventories/group_vars/gighive/gighive.yml` — `gighiveinfra` repo — identical changes to gighive2.yml (lines 38–42 are the same across all three).
3. `ansible/inventories/group_vars/prod/prod.yml` — `gighiveinfra` repo — identical changes to gighive2.yml.
4. `ansible/roles/cloud_init/defaults/main.yml` — `gighiveinfra` repo — add `cloud_arch: "amd64"` as a safe default; overridden at runtime by the detection `set_fact`.
5. `ansible/roles/cloud_init/tasks/main.yml` — `gighiveinfra` repo — add arch detection (no run_once), assert, debug; rename `cloud_image_vmdk` → `cloud_image_src`; branch conversion for QCOW2; guard KVM check for Linux-only; branch NIC detection for macOS; branch prereq install for macOS; conditionally branch `createvm --ostype`; branch ISO generation command for macOS; add T-CI-2 (VDI size check after conversion) and T-CI-1 (guest uname check after SSH wait).
6. `ansible/roles/cloud_init/tasks/nat.yml` — `gighiveinfra` repo — add arch detection, assert, debug; rename `cloud_image_vmdk` → `cloud_image_src`; branch conversion for QCOW2; branch prereq install for macOS; branch ISO generation command for macOS; full macOS NIC resolution (4 branched detection tasks + 4 downstream normalizations).
7. `ansible/roles/cloud_init/tasks/test.yml` — `gighiveinfra` repo — add arch detection, assert, debug; rename `cloud_image_vmdk` → `cloud_image_src`; branch conversion for QCOW2; branch prereq install for macOS; branch ISO generation command for macOS; branch NIC detection for macOS.

### New

_(None — no new files required for this refactor.)_

### Unchanged

- `ansible/inventories/group_vars/all.yml` — `cloud_init_files_dir` definition is architecture-neutral; no change needed.
- `ansible/playbooks/resize_vdi.yml` — references only `cloud_image_vdi` (not `cloud_image_vmdk`); architecture-neutral; no change needed.
- `ansible/roles/cloud_init_disable/` — runs inside the Ubuntu guest over SSH after provisioning; references no architecture-specific variables; netplan, cloud-init, virtio-offload, and systemd operations are identical on ARM64 Ubuntu as on amd64. No code changes required. During the first ARM64 test run, verify that `ansible_facts.default_ipv4.interface` resolves to the correct NIC name inside the VirtualBox ARM64 guest.

---

## Topology Change Notes (pop-os Retirement)

This refactor is scoped to `cloud_init`. The broader topology change — retiring pop-os as the Ansible control machine — requires separate action:

1. **Install VirtualBox 7.x for Apple Silicon** on the Mac from [virtualbox.org](https://www.virtualbox.org). This is a hard prerequisite; the playbook cannot create any VM without it.
2. Install Ansible on the Mac: `brew install ansible`.
3. Install required Ansible collections: `ansible-galaxy collection install community.general community.docker`.
4. Move `gighiveinfra` repo to a local Mac path (remove NFS dependency).
5. Update `SKILL.md` to reflect new control machine, repo root, and server inventory.
6. Verify `ansible_connection: local` tasks still work when the control machine is macOS.
7. Update any `become: yes` tasks that relied on pop-os having passwordless sudo — macOS may require a different sudoers entry or `--ask-become-pass`.

---

## Progress

### Completed

_(Nothing implemented yet.)_

### Remaining — This Refactor

- [ ] Run `VBoxManage list ostypes | grep -i ubuntu` on the Mac to confirm the correct ARM64 ostype string before coding the branch.
- [ ] Verify nocloud ISO generation (`cloud-localds` equivalent) on macOS — confirm `mkisofs` drop-in compatibility or identify alternative before closing the prereq task.
- [ ] Verify ARM64 Docker image availability for all stack containers: `apacheWebServer` (ubuntu-apache-img), `mysqlServer` (mysql:8.x), `tusd` (tusproject/tusd), `ai-worker` (custom). Check each image's manifest for `linux/arm64` support before declaring success on the full provisioning workflow.
- [ ] Update `cloud_init/defaults/main.yml` — add `cloud_arch: "amd64"` default.
- [ ] Update `group_vars/gighive2/gighive2.yml`, `group_vars/gighive/gighive.yml`, `group_vars/prod/prod.yml` — replace hardcoded arch strings; rename `cloud_image_vmdk` → `cloud_image_src`; add `cloud_image_ext`.
- [ ] Update `cloud_init/tasks/main.yml` — arch detection (no run_once), assert, debug, rename, branch conversion, OS guards, branch ISO generation.
- [ ] Update `cloud_init/tasks/nat.yml` — arch detection, assert, debug, rename, branch conversion, prereq OS split, branch ISO generation, full macOS NIC resolution (Step 12).
- [ ] Update `cloud_init/tasks/test.yml` — arch detection, assert, debug, rename, branch conversion, prereq OS split, branch ISO generation, branch NIC detection (Step 7).
- [ ] Add T-CI-2 (VDI size check) after the conversion block and T-CI-1 (guest uname check) after `Wait for SSH availability` in `cloud_init/tasks/main.yml`.
- [ ] Run playbook from Mac with `--tags cloud_init` against a fresh VM slot; confirm SSH reachable, `uname -m` inside VM returns `aarch64`, VDI > 1 GB.

### Remaining — Follow-on Tasks

- [ ] Update `SKILL.md` topology table once pop-os is retired and Ansible runs from the Mac.
- [ ] Evaluate VirtualBox vs UTM if bridged networking proves unreliable on macOS.
