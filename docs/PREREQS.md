---
title: Parts List
layout: default
---
# GigHive Parts List (Linux x86-64)

This document outlines the minimum requirements for installing and running GigHive.

GigHive supports two main installation paths:
- **Quickstart**: fastest path for users who already have Docker installed and want to run the prebuilt one-shot bundle
- **Full Build**: build/deploy path for operators or developers using the full Ansible-based setup

For extended details, see `PREREQS_table.md`.

---

## ✅ Quickstart prerequisites

Use this path if you want the simplest install experience and already have Docker available.

| Component      | Requirement / Notes |
|----------------|---------------------|
| **CPU/OS**     | Linux **x86-64** host |
| **Docker**     | Engine ≥ **24.x** |
| **Compose**    | **v2 plugin** (`docker compose version`) |
| **Networking** | Outbound access to download the GigHive bundle and required container images |
| **Ports**      | Local ability to expose **443** for the web app and **3306** if MySQL port exposure matters in your environment |
| **Resources**  | ≥ **4 vCPU**, **6–8 GB RAM**, **30+ GB free disk**, depending on library size |
| **Browser**    | A modern browser for initial access and validation |

### Quickstart notes

- Quickstart is intended for end users who want to get GigHive running with the least setup friction.
- You do **not** need Ansible or Terraform for the quickstart path.
- The quickstart bundle includes the application stack and starts it with Docker Compose.
- A self-signed certificate is used by default, so your browser will show a security warning until you replace it with a trusted cert.

---

## ✅ Full build prerequisites

Use this path if you are doing a full environment build, repeatable deployment, or infrastructure-driven setup.

| Component      | Requirement / Notes |
|----------------|---------------------|
| **CPU/OS**     | Linux **x86-64** host (Ubuntu 24.04/22.04 LTS or Pop-OS recommended for control workflows) |
| **Ansible**    | **2.16+** on the control node |
| **Python**     | **3.10+** on target hosts |
| **Terraform**  | **v1.12.1+** if using Azure as the build target |
| **xorriso**    | **1.5+** if using VirtualBox / ISO / cloud-init image creation workflows |
| **Docker**     | Engine ≥ **24.x** on the target host |
| **Compose**    | **v2 plugin** (`docker compose version`) |
| **Git**        | Required |
| **Networking** | Outbound access to Docker Hub / GitHub and inbound **80/443** if hosting externally |
| **Resources**  | ≥ **4 vCPU**, **6–8 GB RAM**, **30+ GB free disk**, depending on library size |

### Full build notes

- Full build is intended for operators, developers, and repeatable deployment workflows.
- This path is appropriate when you want to provision and configure GigHive using the repo’s Ansible playbooks.
- Azure-based deployments require Terraform in addition to Ansible.
- VirtualBox / image-generation workflows may require `xorriso`.
- Full build is the better fit when you want infrastructure automation, customization, or a reproducible environment setup.
- [DEPENDENCIES.md](DEPENDENCIES.md)
- [feature_set.md](feature_set.md)

---

## 🔎 App-specific notes for either version

- GigHive supports authenticated viewer, uploader, and admin roles.
- Default installs will include sample media, depending on the install path and configuration.
- Media library size strongly affects disk requirements.
- If you plan to expose GigHive publicly, you should also plan for trusted TLS certificates, DNS, and firewall configuration.
