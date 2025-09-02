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
