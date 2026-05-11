-- 037: Optional link from work_orders → arc_requests
--
-- Mirrors source_concern_id (added in 030). Lets a board promote an
-- approved Architectural Review to a Work Order (e.g. "Inspect ARC #12
-- completion at Unit 412") with one click, and the WO carries a back-link
-- so the ARC's detail page can list its spawned work orders.
--
-- Additive — no existing data touched.

ALTER TABLE work_orders
    ADD COLUMN source_arc_id INT NULL AFTER source_concern_id,
    ADD KEY idx_wo_arc (source_arc_id),
    ADD CONSTRAINT fk_wo_arc FOREIGN KEY (source_arc_id) REFERENCES arc_requests(id) ON DELETE SET NULL;
