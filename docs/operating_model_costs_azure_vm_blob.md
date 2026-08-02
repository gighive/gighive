# Azure Operating Cost Model (Virtual Machine + Blob Storage)

This document provides a simple monthly operating cost estimate for running a standard GigHive deployment on Microsoft Azure.

These figures are based on:

- A real GigHive deployment running for one week in Azure.
- The default GigHive Terraform configuration.
- Azure East US pricing.
- Continuous 24x7 operation.

> **Note**
>
> These are planning estimates only. Actual Azure charges vary by region, negotiated pricing, storage usage, and outbound bandwidth.

---

# Default GigHive Azure Configuration

The standard GigHive Azure deployment uses:

- **Virtual Machine**
  - Azure Standard_B2ms
  - 2 vCPUs
  - 8 GiB RAM
  - Ubuntu Server 24.04 LTS

- **Operating System Disk**
  - Premium SSD LRS
  - 64 GB

- **Media Storage**
  - Azure Blob Storage
  - Standard Hot LRS
  - Private blob container

- **Networking**
  - Virtual Network
  - Subnet
  - Network Security Group
  - Static Public IP Address

---

# Estimated Monthly Cost

| Azure Service | GigHive Configuration | Estimated Monthly Cost |
|----------------|----------------------|-----------------------:|
| **Virtual Machine** | Standard_B2ms Linux VM (2 vCPU / 8 GiB RAM) | **~$55** |
| **Bandwidth** | Internet uploads are generally free. Downloads and media streaming are usage-based. | **~$0 while idle** |
| **Storage** | 64 GB Premium LRS OS disk + approximately 246 GiB Standard Hot LRS Blob Storage | **~$13–14** |
| **Virtual Network** | VNet, subnet, NSG, NIC, Storage Service Endpoint, Static Public IP | **~$3–4** |

## Estimated Idle Monthly Total

**Approximately $72/month**

This estimate assumes:

- The server runs continuously.
- Approximately 246 GiB of media is stored.
- There is little or no user traffic.
- No significant outbound media streaming.

---

# Simple Cost Breakdown

For the default GigHive deployment:

- **Virtual Machine — approximately $55/month**

  Azure Standard_B2ms running Ubuntu Server 24.04 continuously.

- **Bandwidth — approximately $0/month while idle**

  Uploading media into Azure is generally free.

  Costs increase as users stream or download media.

- **Storage — approximately $13–14/month**

  Includes:

  - 64 GB Premium LRS operating system disk
  - Approximately 246 GiB Standard Hot LRS Blob Storage

- **Virtual Network — approximately $3–4/month**

  Includes:

  - Static Public IP Address
  - Virtual Network
  - Subnet
  - Network Security Group
  - Network Interface

---

# Estimated Cost by Media Library Size

As your media library grows, storage costs increase gradually while the VM cost remains largely unchanged.

| Blob Media Capacity | Estimated Monthly Cost |
|--------------------:|-----------------------:|
| **250 GiB** | **~$72/month** |
| **500 GiB** | **~$78–85/month** |
| **1 TiB** | **~$90–105/month** |
| **2 TiB** | **~$110–140/month** |

These estimates assume the same Azure VM size and configuration.

---

# What Increases Cost?

The largest factors affecting operating cost are:

1. A larger virtual machine.
2. More stored media.
3. Users downloading or streaming media.
4. Higher outbound bandwidth usage.

Most GigHive installations will spend far more on compute and outbound media traffic than on storage itself.

---

# Summary

The default GigHive Azure deployment is intentionally designed to provide a predictable and affordable operating cost while giving you complete ownership of your infrastructure and media.

For many personal, club, and community deployments, the complete Azure hosting cost is approximately:

> **Approximately $72 per month**

before significant user traffic or media streaming.

And when you're done, deleting the infrastructure stops the cost immediately — no contract to wait out.

---

# Azure Infrastructure vs. Traditional Web Hosting

It is natural to compare the monthly cost of running GigHive on Azure with the cost of a typical web hosting plan.

However, these are fundamentally different products.

| Traditional Hosted Website | GigHive on Your Own Azure Infrastructure |
|----------------------------|------------------------------------------|
| Rent an application or shared hosting | Rent your own cloud infrastructure |
| Limited to the provider's features | Full control over the software and infrastructure |
| Media and data are stored by the provider | You own your media and metadata |
| Upgrades are controlled by the provider | You decide when and how to upgrade |
| Features are limited to the provider's roadmap | Customize or extend GigHive however you like |
| Export options may be limited | Your data is always portable |
| Usually shared with many other customers | Dedicated virtual machine and private storage |
| Lower monthly cost | Greater ownership, flexibility, and control |
| Keep paying until end of contract | Immediate cost elimination by deleting the infrastructure |

---

# Why Is GigHive Different from Traditional Web Hosting?

Many website hosting plans advertise prices between **$5 and $20 per month**. Those services are inexpensive because hundreds or even thousands of customers typically share the same servers and infrastructure.

A GigHive deployment is fundamentally different because you are operating your own cloud infrastructure instead of renting space within someone else's platform.

Each installation runs on its own dedicated virtual machine with its own private Blob Storage account, networking, operating system, and database. Rather than renting space inside someone else's application, you are operating your own cloud infrastructure.

For many GigHive users, the additional monthly cost provides significant benefits:

- Complete ownership of your media and metadata
- Open source software with no vendor lock-in
- Freedom to customize and extend the application
- Full control over upgrades, backups, and security
- The ability to integrate GigHive with your own applications and workflows
- Predictable cloud pricing that scales with your media library

GigHive is designed for users who value ownership and control over their platform.

Although this document uses Microsoft Azure as the reference deployment, GigHive itself is not tied to Azure or to any proprietary hosting platform.

Because GigHive is open source, your media, database, and application remain under your control. If your needs change, you can migrate to another cloud provider or even run GigHive on your own hardware.

For many organizations, the monthly infrastructure cost is the price of owning the platform instead of renting it.
