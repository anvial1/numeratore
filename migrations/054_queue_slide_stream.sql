-- Migration: 054_queue_slide_stream
-- A monitor tile can also show a live online TV channel or play a radio station.
-- stream_type = 'tv' | 'radio' (NULL = photo / video tile), stream_url = HLS (.m3u8), MP3/AAC
-- stream or a YouTube link, stream_logo = channel logo from the channel list,
-- stream_seconds = how long the tile stays on before the next one (a live stream never ends).
-- TV sound follows video_audio; a radio always plays its sound (muted while a number is called).

ALTER TABLE queue_slides
    ADD COLUMN IF NOT EXISTS stream_type    VARCHAR(10)  NULL DEFAULT NULL AFTER video_audio,
    ADD COLUMN IF NOT EXISTS stream_url     VARCHAR(500) NULL DEFAULT NULL AFTER stream_type,
    ADD COLUMN IF NOT EXISTS stream_logo    VARCHAR(500) NULL DEFAULT NULL AFTER stream_url,
    ADD COLUMN IF NOT EXISTS stream_seconds INT          NOT NULL DEFAULT 60 AFTER stream_logo;
