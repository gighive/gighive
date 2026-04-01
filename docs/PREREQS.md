---
title: Parts List
layout: default
---
# GigHive Parts List (Linux x86-64)

This document outlines the minimum requirements for installing and running GigHive.

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

## 🔎 App-specific notes

- GigHive supports authenticated viewer, uploader, and admin roles.
- Default installs will include sample media, depending on the install path and configuration.
- Media library size strongly affects disk requirements.
- If you plan to expose GigHive publicly, you should also plan for trusted TLS certificates, DNS, and firewall configuration.
