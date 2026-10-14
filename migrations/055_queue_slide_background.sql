-- Migration: 055_queue_slide_background
-- A radio tile can be background music instead of a tile of its own: stream_background = 1 →
-- the monitor plays it all the time while the photos (and the other tiles) rotate. It goes quiet
-- while a number is called and while a tile with its own sound is on (video / TV with audio, radio tile).

ALTER TABLE queue_slides
    ADD COLUMN IF NOT EXISTS stream_background TINYINT(1) NOT NULL DEFAULT 0 AFTER stream_seconds;
