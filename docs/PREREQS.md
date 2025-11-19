---
title: Parts List
layout: default
---
# GigHive Parts List (Linux x86-64)

This document outlines the **minimum requirements** for running the GigHive framework. For extended details, see `PREREQS_table.md`.

---

## ✅ Required

| Component      | Requirement / Notes |
|----------------|---------------------|
| **CPU/OS**     | Linux **x86-64** host (Ubuntu 24.04/22.04 LTS or Pop-OS recommended) |
| **Ansible**    | ≥ **2.16** on control node; targets need **Python ≥ 3.10** |
| **Terraform**  | ≥ **v1.12.1** on control node (if using Azure as build target) |
| **xorriso**    | ≥ **1.5** (if using virtualbox as build target for ISO/cloud-init image creation) |
| **Docker**     | Engine ≥ **24.x** (multi-arch pulls) |
| **Compose**    | **v2 plugin** (`docker compose version`) |
| **Git**        | Required |
| **Networking** | Outbound to Docker Hub/GitHub; inbound **80/443** if hosting externally |
| **Resources**  | ≥ **4 vCPU**, **6–8 GB RAM**, **30+ GB free disk but depends on size of your library ** |

---

## 🔎 App-specific Notes

### MySQL (containerized)
- Version: **8.0**; ensure volume mounts for persistence  
- CSV import: check `secure-file-priv` or use `LOAD DATA LOCAL INFILE`  

### Apache/PHP (containerized)
- Ensure media/static mounts have adequate space + backup plan  

### DNS
- A/AAAA records set  
- If behind Cloudflare: align proxied/non-proxied with TLS choice  

