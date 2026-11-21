variable "primary_region" {
  type        = string
  description = "AWS Primary Carrier Region (Dhaka Core)"
  default     = "ap-southeast-1"
}

variable "secondary_region" {
  type        = string
  description = "AWS Secondary Disaster Recovery Region (Chattogram Standby)"
  default     = "ap-south-1"
}

variable "environment" {
  type        = string
  description = "Carrier Deployment Environment"
  default     = "prod"
}

variable "anycast_vip" {
  type        = string
  description = "BGP Announced Anycast IPv4 VIP"
  default     = "103.145.10.1/32"
}
