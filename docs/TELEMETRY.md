---
title: Installation Telemetry Design
layout: default
---

# GigHive Installation Telemetry

## Overview

This document outlines the design and considerations for implementing installation telemetry in GigHive to track adoption and usage patterns.

## Goals

- Track number of GigHive installations
- Understand deployment patterns (VirtualBox vs Azure vs Bare Metal)
- Monitor version adoption
- Help prioritize features and improvements
- Maintain user privacy and transparency

## Industry Examples

Many major open-source projects use telemetry to improve their products:

### Projects with Active Telemetry

| Project | Method | What They Collect | Opt-Out |
|---------|--------|-------------------|---------|
| **Homebrew** | Google Analytics | Install counts, OS version, command usage | `HOMEBREW_NO_ANALYTICS=1` |
| **Next.js** | Custom endpoint | Command usage, build times, plugins | `npx next telemetry disable` |
| **VS Code** | Application Insights | Feature usage, errors, performance | Settings → Telemetry → Off |
| **Terraform** | Checkpoint service | Command usage, provider usage, version | `CHECKPOINT_DISABLE=1` |
| **.NET Core** | Built-in telemetry | Command usage, SDK version, OS | `DOTNET_CLI_TELEMETRY_OPTOUT=1` |
| **Gatsby** | Custom telemetry | Build times, plugin usage, errors | `gatsby telemetry --disable` |
| **Docker Desktop** | Built-in | Usage statistics, errors | Settings toggle |
| **Ansible** | Callback plugins | Module usage, versions (opt-in) | Default off |

### Common Patterns

**Typically Collected:**
- ✅ Version information
- ✅ OS/platform details
- ✅ Installation method
- ✅ Timestamp
- ⚠️ IP address (often hashed/anonymized)
- ❌ Never: Personal data, file contents, credentials

**Best Practices:**
1. **Always provide opt-out** - Make it easy to disable
2. **Be transparent** - Document exactly what's collected
3. **Keep it anonymous** - No PII (Personally Identifiable Information)
4. **Collect minimally** - Only what's needed
5. **Use secure transport** - HTTPS only
6. **Disclose prominently** - Mention in README/docs

## Proposed Implementation

### Option A: GitHub Issues API (Recommended)

Use GitHub's Issues API to create installation reports. This approach is:
- **Free** - No infrastructure costs
- **Transparent** - All data publicly visible (or in private repo)
- **Simple** - No server/database to maintain
- **Searchable** - GitHub's built-in analytics

#### Repository Structure

**Create separate repo: `gighive/gighive-telemetry`**

**Advantages:**
- Keeps main repo clean
- Can be private for IP address privacy
- Won't spam main repo watchers
- Dedicated analytics without noise
- Clear separation of concerns

**Issue Format:**

**Title:** `Installation #123: v1.0.0 (vbox) - 2025-11-02`

**Body:**
```markdown
## Installation Report #123

### Version Information
- **GigHive Version**: 1.0.0
- **Git Commit**: b4d9c8a7
- **Git Branch**: main
- **Git Tag**: v1.0.0

### Deployment Information
- **Type**: VirtualBox
- **OS**: Ubuntu 22.04.3 LTS
- **Timestamp**: 2025-11-02T13:13:45Z

### Network Information
- **IP Address**: 192.168.1.100 (or hashed)
- **Install UUID**: a7f3c2e1-4b9d-4c8a-9e7f-1a2b3c4d5e6f

---
*Automated installation report*
```

**Labels:**
- `installation` - All installations
- `vbox` / `azure` / `baremetal` - Deployment type
- `v1.0.0` / `v1.1.0` - Version tags

#### Analytics Queries

```bash
# Total installations
gh issue list --repo gighive/gighive-telemetry --label installation --state all --limit 1000 | wc -l

# VirtualBox installations
gh issue list --repo gighive/gighive-telemetry --label vbox --state all

# Installations this month
gh issue list --repo gighive/gighive-telemetry --label installation --search "created:>2025-11-01"

# Version breakdown
gh issue list --repo gighive/gighive-telemetry --label installation --json labels \
  --jq '.[].labels[].name' | grep "^v" | sort | uniq -c
```

### Option B: Simple HTTP Endpoint

Create a lightweight endpoint (Cloudflare Workers, AWS Lambda, etc.) that:
- Accepts POST requests from Ansible
- Validates and sanitizes data
- Creates GitHub issue or stores in database
- No tokens exposed in Ansible code
- Can add rate limiting and spam protection

### Option C: Aggregate Daily Reports

Instead of per-installation issues:
- Collect data locally during installation
- Aggregate and submit daily/weekly summaries
- Reduces GitHub API usage
- Less noisy but less granular

## Data Collection Details

### Version Information

**Approach: Dual-source version tracking**

1. **VERSION file** (`/home/sodo/scripts/gighive/VERSION`)
   - Contains semantic version: `1.0.0`
   - Manually updated for releases
   - Simple and authoritative

2. **Git metadata** (collected via Ansible)
   ```yaml
   - name: Get git commit hash
     command: git rev-parse --short=8 HEAD
     args:
       chdir: "{{ repo_root }}"
     register: git_commit_short
     
   - name: Get git tag if exists
     command: git describe --tags --abbrev=0
     args:
       chdir: "{{ repo_root }}"
     register: git_latest_tag
     
   - name: Get git branch
     command: git rev-parse --abbrev-ref HEAD
     args:
       chdir: "{{ repo_root }}"
     register: git_branch
   ```

**Combined version string examples:**
- `v1.0.0+0.a7f3c2e1` (exactly on tag v1.0.0)
- `v1.0.0+15.b4d9c8a7` (15 commits after v1.0.0)
- `1.0.0.b4d9c8a7` (VERSION file + commit)

### Deployment Information

```yaml
deployment_type: "{{ 'vbox' if 'virtualbox' in inventory_file else 
                      'azure' if 'azure' in inventory_file else 
                      'baremetal' }}"
os_info: "{{ ansible_distribution }} {{ ansible_distribution_version }}"
timestamp: "{{ ansible_date_time.iso8601 }}"
```

### Network Information

**IP Address Options:**

1. **Full IP** (least private)
   ```yaml
   ip_address: "{{ ansible_default_ipv4.address }}"
   ```

2. **Hashed IP** (more private)
   ```yaml
   ip_hash: "{{ (ansible_default_ipv4.address + secret_salt) | hash('sha256') }}"
   ```

3. **Country/Region only** (most private)
   ```yaml
   # Use GeoIP lookup or omit entirely
   region: "US-East"
   ```

4. **No IP** (maximum privacy)
   - Just track install UUID for uniqueness

**Install UUID:**
```yaml
install_uuid: "{{ 999999999 | random | to_uuid }}"
```

## Ansible Implementation

### Role Structure

```
ansible/roles/installation_tracking/
├── defaults/
│   └── main.yml          # Default variables
├── tasks/
│   └── main.yml          # Main tracking tasks
└── templates/
    └── issue_body.md.j2  # GitHub issue template
```

### Task Flow

```yaml
- name: Installation Tracking
  block:
    # 1. Gather version information
    - name: Read VERSION file
      slurp:
        src: "{{ repo_root }}/VERSION"
      register: version_file
      
    - name: Get git metadata
      # ... git commands ...
      
    # 2. Generate install UUID
    - name: Generate install UUID
      set_fact:
        install_uuid: "{{ 999999999 | random | to_uuid }}"
        
    # 3. Report to GitHub
    - name: Report installation to GitHub
      uri:
        url: "https://api.github.com/repos/gighive/gighive-telemetry/issues"
        method: POST
        headers:
          Authorization: "token {{ github_tracking_token }}"
          Accept: "application/vnd.github.v3+json"
        body_format: json
        body:
          title: "Installation: {{ ansible_default_ipv4.address }} ({{ deployment_type }}) - {{ ansible_date_time.date }}"
          body: "{{ lookup('template', 'issue_body.md.j2') }}"
          labels:
            - installation
            - telemetry
            - "{{ deployment_type }}"
            - "v{{ gighive_version }}"
        status_code: 201
      when: enable_installation_tracking | default(true)
      ignore_errors: yes  # Don't fail installation if tracking fails
```

### Integration into site.yml

```yaml
- name: Report Installation (Telemetry)
  hosts: target_vms
  gather_facts: true
  roles:
    - role: installation_tracking
      tags: [ installation_tracking ]
```

## Configuration

### group_vars/gighive.yml

```yaml
# Installation tracking (helps us understand GigHive adoption)
# Set to false to opt out
enable_installation_tracking: true

# GitHub API token for telemetry (use ansible-vault in production)
# github_tracking_token: "ghp_xxxxxxxxxxxxxxxxxxxx"
```

### Opt-Out Mechanism

Users can disable tracking by:

1. **Setting variable:**
   ```yaml
   enable_installation_tracking: false
   ```

2. **Skipping role:**
   ```bash
   ansible-playbook ... --skip-tags installation_tracking
   ```

3. **Environment variable (future):**
   ```bash
   export GIGHIVE_NO_TELEMETRY=1
   ```

## Privacy & Legal Considerations

### What's Legal to Collect

✅ **Generally OK:**
- Anonymous usage statistics
- Version/OS information
- Installation counts
- Feature usage (anonymous)
- Error reports (sanitized)

⚠️ **Requires Disclosure:**
- IP addresses (can identify users)
- Hostnames
- Geographic location
- Hardware identifiers

❌ **Avoid:**
- Personal information
- File contents
- Credentials/secrets
- Detailed system information without consent

### Recommended Data Collection

**Collect (Safe & Useful):**
- ✅ GigHive version (1.0.0)
- ✅ Git commit hash
- ✅ Deployment type (vbox/azure/baremetal)
- ✅ OS version (Ubuntu 22.04)
- ✅ Timestamp
- ✅ Install UUID (random, not tied to user)
- ⚠️ IP address (hash it or just track country)

**Don't Collect:**
- ❌ Usernames
- ❌ Passwords/credentials
- ❌ Media file names/metadata
- ❌ Database contents
- ❌ Detailed system specs

## Documentation & Transparency

### README.md Addition

```markdown
## 📊 Telemetry

GigHive collects anonymous installation statistics to help us understand
adoption and improve the project. This is similar to Homebrew, Next.js,
and other open-source projects.

**What we collect:**
- Installation timestamp
- GigHive version and git commit
- Deployment type (VirtualBox/Azure/Bare Metal)
- Operating system version
- Anonymized installation ID

**What we DON'T collect:**
- Personal information
- Media files or metadata
- Passwords or credentials
- Detailed system information

**Opt-out:**
Set `enable_installation_tracking: false` in 
`ansible/inventories/group_vars/gighive.yml`

**View all telemetry data:**
https://github.com/gighive/gighive-telemetry
```

### Installation Output

```
TASK [installation_tracking : Report installation to GitHub] *******************
ok: [gighive] => {
    "msg": "Installation reported successfully. Thank you for helping improve GigHive!"
}

To opt out of telemetry, set enable_installation_tracking: false in group_vars/gighive.yml
```

## GitHub API Setup

### Personal Access Token (PAT)

1. **Create token:**
   - Go to: https://github.com/settings/tokens
   - Click "Generate new token (classic)"
   - Name: "GigHive Telemetry"
   - Scopes:
     - `public_repo` (for public telemetry repo)
     - `repo` (for private telemetry repo)
   - Expiration: No expiration or 1 year

2. **Store securely:**
   ```bash
   # Encrypt with ansible-vault
   ansible-vault encrypt_string 'ghp_xxxxxxxxxxxx' --name 'github_tracking_token'
   ```

3. **Add to group_vars:**
   ```yaml
   github_tracking_token: !vault |
             $ANSIBLE_VAULT;1.1;AES256
             ...encrypted...
   ```

### Rate Limits

- **Authenticated**: 5,000 requests/hour
- **Unauthenticated**: 60 requests/hour

For GigHive's expected usage, authenticated rate limits are more than sufficient.

## Alternative Approaches

### 1. GitHub Discussions (GraphQL)

More complex but cleaner than issues:
```graphql
mutation {
  createDiscussion(input: {
    repositoryId: "..."
    categoryId: "..."
    title: "Installation Report"
    body: "..."
  }) {
    discussion {
      id
    }
  }
}
```

### 2. Simple Log File

Append to a public gist or file:
```yaml
- name: Append to telemetry log
  uri:
    url: "https://api.github.com/gists/{{ gist_id }}"
    method: PATCH
    body:
      files:
        telemetry.log:
          content: "{{ telemetry_line }}"
```

### 3. Third-Party Services

- **PostHog** (open-source analytics)
- **Plausible** (privacy-focused)
- **Umami** (simple, self-hosted)
- **Matomo** (self-hosted Google Analytics alternative)

## Metrics & Analytics

### Key Metrics to Track

1. **Total Installations**
   - Overall count
   - Growth rate over time

2. **Deployment Distribution**
   - VirtualBox: X%
   - Azure: Y%
   - Bare Metal: Z%

3. **Version Adoption**
   - Current version: X%
   - Previous version: Y%
   - Upgrade rate

4. **Geographic Distribution** (if tracking)
   - By country/region
   - Helps with CDN/mirror decisions

5. **OS Distribution**
   - Ubuntu versions
   - Other distros (if supported)

### Visualization

**GitHub Issues Dashboard:**
- Use GitHub Projects for visual boards
- Filter by labels for charts
- Export to CSV for external analysis

**External Tools:**
- Export to Google Sheets
- Use Grafana with GitHub API
- Custom dashboard with GitHub Actions

## Implementation Checklist

- [ ] Create `gighive/gighive-telemetry` repository (public or private)
- [ ] Generate GitHub Personal Access Token
- [ ] Create `installation_tracking` Ansible role
- [ ] Add VERSION file to repo root (✅ Done: 1.0.0)
- [ ] Add version collection tasks (VERSION + git)
- [ ] Add GitHub API reporting task
- [ ] Add opt-out variable to group_vars
- [ ] Integrate role into site.yml
- [ ] Update README.md with telemetry disclosure
- [ ] Create TELEMETRY.md documentation (✅ This file)
- [ ] Test with private installation
- [ ] Verify opt-out mechanism works
- [ ] Monitor GitHub API rate limits

## Future Enhancements

### Phase 2: Enhanced Tracking
- Update notifications (check for new versions)
- Feature usage tracking (which pages are accessed)
- Error reporting (opt-in crash reports)
- Performance metrics (installation time, resource usage)

### Phase 3: User Benefits
- Update notifications in web UI
- Usage statistics dashboard
- Community size/growth display
- Anonymous comparison metrics

### Phase 4: Advanced Analytics
- Retention tracking (re-installations)
- Upgrade paths (version transitions)
- Deployment success rates
- Common configuration patterns

## Security Considerations

1. **Token Security**
   - Use ansible-vault for encryption
   - Rotate tokens periodically
   - Minimum required permissions only

2. **Data Sanitization**
   - Never log sensitive data
   - Validate all inputs
   - Hash or omit IP addresses

3. **Failure Handling**
   - Never fail installation if tracking fails
   - Log errors but continue
   - Retry logic with exponential backoff

4. **Rate Limiting**
   - Respect GitHub API limits
   - Implement client-side throttling
   - Consider batch reporting for high volume

## References

- [Homebrew Analytics](https://docs.brew.sh/Analytics)
- [Next.js Telemetry](https://nextjs.org/telemetry)
- [Terraform Checkpoint](https://checkpoint.hashicorp.com)
- [GitHub Issues API](https://docs.github.com/en/rest/issues)
- [Ansible URI Module](https://docs.ansible.com/ansible/latest/collections/ansible/builtin/uri_module.html)

## Decision Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2025-11-02 | Use dual-source versioning (VERSION file + git) | Combines simplicity with detailed tracking |
| 2025-11-02 | Document telemetry design before implementation | Allows for informed decision-making |
| TBD | Choose GitHub Issues vs alternative | Pending decision on public/private repo |
| TBD | Decide on IP address handling | Privacy vs utility tradeoff |

---

*Last Updated: 2025-11-02*
*Status: Design Phase - Not Yet Implemented*
