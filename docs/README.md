---
title: Readme
layout: default
---
# GigHive Music and Video Library Setup

GigHive is an **open-source deployment** for hosting your own media library, a band’s library or fan videos, or even videos uploaded by guests from events like a wedding.  There are two pieces to Gighive:
- A pre-built web-accessible media library waiting to be populated, fast Apache and TUS streams and MySQL database instances. It includes a few audio and video clips donated from one of our other users as a sample.
- Companion iPhone app for fans and wedding guests to upload content (web-based upload also built-in).

The automation spins up a fully reproducible environment using **Docker**.  It has a very simple interface: a splash page, a single database of stored videos and an upload utility.

This project is designed to be portable, easy to deploy, and suitable for local development.

## Components

| Component      | Requirement / Notes |
|----------------|---------------------|
| **CPU/OS**     | Linux **x86-64** host |
| **Docker**     | Engine ≥ **24.x** |
| **Compose**    | **v2 plugin** (`docker compose version`) |
| **Networking** | Outbound access to download the GigHive bundle and required container images |
| **Ports**      | Local ability to expose **443** for the web app |
| **Resources**  | ≥ **4 vCPU**, **6–8 GB RAM**, **30+ GB free disk**, depending on library size |
| **Browser**    | A modern browser for initial access and validation |

More detailed listing [here](PREREQS.html).

## Architecture (logical)

<a href="images/architecture.png" target="_blank">
  <img src="images/architecture.png" alt="GigHive Architecture Diagram" height="400" style="cursor: pointer;">
</a>

*Click the diagram above to view full size*

## Suggested Platforms
- Gighive runs very efficiently on an [Orange Pi 5](https://a.co/d/90UDcvi) or [GMKtech mini PC](https://www.amazon.com/dp/B0F8NL4ST4?ref=ppx_yo2ov_dt_b_fed_asin_title&th=1).  Stuff a few NVMe's in either and you'll have plenty of storage for your videos.  We've tested on both of them.
- Note that both cpu architectures are supported: amd64, arm64

## Secrets and Default Passwords

Secrets must be changed before any Internet-exposed or production deployment.  You will see a step in the install for this.

---

# Setup

## Quickstart (one-shot bundle)

[Quickstart Setup](setup_instructions_quickstart.html)

---

### License
GigHive is dual-licensed:

**[Licenses](LICENSE.md)**: Covers both the AGPL v3 license and the commercial license model.

---

## 🤝 Contributing
Contributions welcome! Please open issues and pull requests.  

👉 [Contact us](mailto:contactus@gighive.app) for commercial licensing or for any other questions regarding Gighive. <img src="images/beelogo.png" alt="GigHive bee mascot" style="height: 1em; vertical-align: middle;">
