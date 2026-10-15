-- Migration: 056_queue_wait_times
-- Waiting time = from taking the ticket to its FIRST call (a recall or "prev" changes called_at,
-- not first_called_at). The estimate printed on the ticket uses the pace of the recent first calls;
-- the statistics use queue_daily_counts.wait_seconds / waited (summed by queueCall()), which survive
-- the midnight and manual resets like the ticket count.

ALTER TABLE queue_tickets
    ADD COLUMN IF NOT EXISTS first_called_at DATETIME NULL DEFAULT NULL AFTER called_at;

ALTER TABLE queue_daily_counts
    ADD COLUMN IF NOT EXISTS wait_seconds BIGINT NOT NULL DEFAULT 0 AFTER tickets,
    ADD COLUMN IF NOT EXISTS waited       INT    NOT NULL DEFAULT 0 AFTER wait_seconds;

-- Tickets already called: their last call is the best we have.
UPDATE queue_tickets SET first_called_at = called_at WHERE called_at IS NOT NULL AND first_called_at IS NULL;

UPDATE queue_daily_counts d
JOIN (SELECT ticket_date, service_id,
             SUM(TIMESTAMPDIFF(SECOND, created_at, first_called_at)) AS s, COUNT(*) AS c
      FROM queue_tickets
      WHERE first_called_at IS NOT NULL AND TIMESTAMPDIFF(SECOND, created_at, first_called_at) BETWEEN 0 AND 14400
      GROUP BY ticket_date, service_id) w
  ON w.ticket_date = d.count_date AND w.service_id = d.service_id
SET d.wait_seconds = w.s, d.waited = w.c
WHERE d.waited = 0;
