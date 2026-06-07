-- Index api_audit_logs by source IP for abuse/forensic queries.
CREATE INDEX idx_audit_ip ON api_audit_logs (ip_address);
