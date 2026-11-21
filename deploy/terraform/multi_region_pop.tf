# Multi-Region Edge POP Infrastructure & Compute Profiles
# Regions: DC1 (Dhaka Core), DC2 (Chattogram Standby), DC3 (Sylhet Regional POP)

variable "tertiary_region" {
  type        = string
  description = "AWS region for Sylhet Regional Edge POP"
  default     = "ap-south-2"
}

provider "aws" {
  region = var.tertiary_region
  alias  = "tertiary"
}

# --- TERTIARY REGION (DC3 - Sylhet Regional Edge POP) ---
resource "aws_vpc" "carrier_dc3" {
  provider             = aws.tertiary
  cidr_block           = "10.300.0.0/16"
  enable_dns_hostnames = true
  enable_dns_support   = true

  tags = {
    Name        = "carrier-vpc-dc3-sylhet"
    Environment = "production-edge"
    Tier        = "carrier-regional-edge"
  }
}

resource "aws_subnet" "dc3_edge_subnet" {
  provider          = aws.tertiary
  vpc_id            = aws_vpc.carrier_dc3.id
  cidr_block        = "10.300.1.0/24"
  availability_zone = "${var.tertiary_region}a"

  tags = {
    Name = "dc3-freeradius-anycast-edge"
  }
}

# --- SECURITY GROUPS ---
# 1. AAA RADIUS Security Group (UDP 1812, 1813, 3799 + BGP 179 + BFD 3784)
resource "aws_security_group" "radius_anycast_sg" {
  provider    = aws.primary
  name        = "carrier-radius-anycast-sg"
  description = "Security group for carrier FreeRADIUS and BIRD2 BGP Anycast"
  vpc_id      = aws_vpc.carrier_dc1.id

  # RADIUS Authentication (UDP 1812)
  ingress {
    description = "RADIUS Authentication"
    from_port   = 1812
    to_port     = 1812
    protocol    = "udp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  # RADIUS Accounting (UDP 1813)
  ingress {
    description = "RADIUS Accounting"
    from_port   = 1813
    to_port     = 1813
    protocol    = "udp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  # RFC 3576 / RFC 5176 CoA / PoD (UDP 3799)
  ingress {
    description = "RADIUS RFC 3576 CoA / PoD"
    from_port   = 3799
    to_port     = 3799
    protocol    = "udp"
    cidr_blocks = ["10.0.0.0/8", "172.16.0.0/12"]
  }

  # BGP Peering (TCP 179)
  ingress {
    description = "BGP Anycast Peering"
    from_port   = 179
    to_port     = 179
    protocol    = "tcp"
    cidr_blocks = ["10.0.0.0/8"]
  }

  # BFD Fast Failover (UDP 3784)
  ingress {
    description = "Bidirectional Forwarding Detection (BFD)"
    from_port   = 3784
    to_port     = 3784
    protocol    = "udp"
    cidr_blocks = ["10.0.0.0/8"]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "radius-anycast-sg"
  }
}

# 2. Database Cluster Security Group (Citus PostgreSQL 5432 + ClickHouse 8123/9000 + Redis 6379)
resource "aws_security_group" "db_cluster_sg" {
  provider    = aws.primary
  name        = "carrier-db-cluster-sg"
  description = "Internal security group for Citus PostgreSQL, ClickHouse, and Redis"
  vpc_id      = aws_vpc.carrier_dc1.id

  ingress {
    description = "Citus Distributed PostgreSQL"
    from_port   = 5432
    to_port     = 5432
    protocol    = "tcp"
    cidr_blocks = ["10.100.0.0/16", "10.200.0.0/16", "10.300.0.0/16"]
  }

  ingress {
    description = "ClickHouse Native & HTTP"
    from_port   = 8123
    to_port     = 9000
    protocol    = "tcp"
    cidr_blocks = ["10.100.0.0/16", "10.200.0.0/16"]
  }

  ingress {
    description = "Redis 7 Sentinel HA"
    from_port   = 6379
    to_port     = 26379
    protocol    = "tcp"
    cidr_blocks = ["10.100.0.0/16", "10.200.0.0/16"]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "carrier-db-cluster-sg"
  }
}

# --- BGP ANYCAST FLOATING VIP ---
output "anycast_vip_ipv4" {
  value       = "103.145.10.1/32"
  description = "Carrier Anycast VIP announced across Dhaka, Chattogram, and Sylhet POPs"
}

output "primary_dc1_vpc_id" {
  value       = aws_vpc.carrier_dc1.id
  description = "VPC ID of Primary DC1 (Dhaka)"
}

output "secondary_dc2_vpc_id" {
  value       = aws_vpc.carrier_dc2.id
  description = "VPC ID of Secondary DC2 (Chattogram DR)"
}

output "tertiary_dc3_vpc_id" {
  value       = aws_vpc.carrier_dc3.id
  description = "VPC ID of Tertiary DC3 (Sylhet Regional Edge)"
}
