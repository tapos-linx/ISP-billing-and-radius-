terraform {
  required_version = ">= 1.5.0"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }
}

provider "aws" {
  region = var.primary_region
  alias  = "primary"
}

provider "aws" {
  region = var.secondary_region
  alias  = "secondary"
}

# --- PRIMARY REGION (DC1 - Dhaka) ---
resource "aws_vpc" "carrier_dc1" {
  provider             = aws.primary
  cidr_block           = "10.100.0.0/16"
  enable_dns_hostnames = true
  enable_dns_support   = true

  tags = {
    Name        = "carrier-vpc-dc1-dhaka"
    Environment = "production"
    Tier        = "carrier-core"
  }
}

resource "aws_subnet" "dc1_aaa_subnet" {
  provider          = aws.primary
  vpc_id            = aws_vpc.carrier_dc1.id
  cidr_block        = "10.100.1.0/24"
  availability_zone = "${var.primary_region}a"

  tags = {
    Name = "dc1-freeradius-anycast"
  }
}

resource "aws_subnet" "dc1_db_subnet" {
  provider          = aws.primary
  vpc_id            = aws_vpc.carrier_dc1.id
  cidr_block        = "10.100.2.0/24"
  availability_zone = "${var.primary_region}b"

  tags = {
    Name = "dc1-citus-clickhouse"
  }
}

# --- SECONDARY REGION (DC2 - Chattogram DR) ---
resource "aws_vpc" "carrier_dc2" {
  provider             = aws.secondary
  cidr_block           = "10.200.0.0/16"
  enable_dns_hostnames = true
  enable_dns_support   = true

  tags = {
    Name        = "carrier-vpc-dc2-chattogram"
    Environment = "dr-standby"
    Tier        = "carrier-standby"
  }
}

resource "aws_subnet" "dc2_aaa_subnet" {
  provider          = aws.secondary
  vpc_id            = aws_vpc.carrier_dc2.id
  cidr_block        = "10.200.1.0/24"
  availability_zone = "${var.secondary_region}a"

  tags = {
    Name = "dc2-freeradius-anycast-standby"
  }
}

# --- INTER-REGION CARRIER VPC PEERING MESH ---
resource "aws_vpc_peering_connection" "dc1_dc2_peering" {
  provider    = aws.primary
  vpc_id      = aws_vpc.carrier_dc1.id
  peer_vpc_id = aws_vpc.carrier_dc2.id
  peer_region = var.secondary_region
  auto_accept = false

  tags = {
    Name = "peering-dc1-to-dc2"
  }
}

resource "aws_vpc_peering_connection_accepter" "dc2_accepter" {
  provider                  = aws.secondary
  vpc_peering_connection_id = aws_vpc_peering_connection.dc1_dc2_peering.id
  auto_accept               = true
}

# --- CARRIER BACKUP & LOG RETENTION S3 STORAGE (730-DAY COMPLIANCE) ---
resource "aws_s3_bucket" "carrier_backups_dc1" {
  provider = aws.primary
  bucket   = "carrier-bss-backups-dc1-${var.environment}"

  lifecycle {
    prevent_destroy = true
  }

  tags = {
    Compliance = "BTRC-730-DAY-RETENTION"
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "retention_730_days" {
  provider = aws.primary
  bucket   = aws_s3_bucket.carrier_backups_dc1.id

  rule {
    id     = "btrc_730_day_archive"
    status = "Enabled"

    transition {
      days          = 90
      storage_class = "GLACIER"
    }

    expiration {
      days = 730
    }
  }
}
