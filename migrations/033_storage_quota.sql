-- 033: Storage quota tracking on associations
--
-- Every association gets 1 GiB free. Additional GiB are paid ($5/mo each per
-- pricing, not enforced here — billing is Phase 3). Super admin sets
-- storage_paid_extra_gb manually until self-serve checkout exists.
--
--   effective_quota_bytes = (1 + storage_paid_extra_gb) * 1073741824
--
-- Additive — existing associations get the 1 GB default automatically.

ALTER TABLE associations
    ADD COLUMN storage_quota_bytes BIGINT NOT NULL DEFAULT 1073741824 AFTER status,
    ADD COLUMN storage_paid_extra_gb INT NOT NULL DEFAULT 0 AFTER storage_quota_bytes;
