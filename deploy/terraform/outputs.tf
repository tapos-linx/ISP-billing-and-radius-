output "dc1_vpc_id" {
  value       = aws_vpc.carrier_dc1.id
  description = "VPC ID of DC1 Primary Carrier Region"
}

output "dc2_vpc_id" {
  value       = aws_vpc.carrier_dc2.id
  description = "VPC ID of DC2 Disaster Recovery Standby Region"
}

output "backup_bucket_arn" {
  value       = aws_s3_bucket.carrier_backups_dc1.arn
  description = "730-day BTRC compliant S3 backup archive ARN"
}

output "anycast_vip_announced" {
  value       = var.anycast_vip
  description = "Anycast VIP prefix announced via FRR/BIRD"
}
