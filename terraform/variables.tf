variable "resource_group_name" {
  type = string
}

variable "location" {
  type = string
}

variable "admin_username" {
  type = string
}

variable "ssh_public_key_path" {
  type = string
}
variable "media_storage_account_name" {
  description = "The name of the media storage account"
  type        = string
}

variable "ssh_allowed_source_prefixes" {
  description = "CIDRs allowed to reach SSH (port 22) through the NSG"
  type        = list(string)
  default     = ["173.54.19.162/32", "192.168.0.0/16", "10.10.0.0/16"]
}
