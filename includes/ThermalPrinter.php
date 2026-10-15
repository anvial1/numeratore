<?php
/**
 * ThermalPrinter — network thermal printer over raw TCP (ESC/POS, port 9100).
 * Prints the eliminacode ticket from the totem (includes/queue.php): the
 * printer cuts the slip itself, no browser dialog.
 *
 * Config: host, port (9100), timeout (5), width (chars per line),
 *         codepage (2 = CP850 for accents).
 */
class ThermalPrinter
{
    private const ESC = "\x1b";
    private const GS  = "\x1d";
    private const LF  = "\n";

    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function isEnabled(): bool
    {
        return !empty($this->cfg['host']);
    }

    /**
     * Print an eliminacode ticket (includes/queue.php): the number very large
     * in the middle, the service above it, people ahead, estimated wait and time below.
     *
     * @param array{brand?:string, service?:string, number?:string, ahead?:string,
     *              wait?:string, time?:string, footer?:string} $t
     * @return array{ok:bool, error?:string, bytes?:int}
     */
    public function printQueueTicket(array $t): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'error' => 'printer_not_configured'];
        }
        $host    = (string) $this->cfg['host'];
        $port    = (int) ($this->cfg['port'] ?? 9100);
        $timeout = (int) ($this->cfg['timeout'] ?? 5);

        $errno = 0; $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            return ['ok' => false, 'error' => trim("$errno $errstr") ?: 'connect_failed'];
        }
        stream_set_timeout($fp, $timeout);

        $w    = (int) ($this->cfg['width'] ?? 48);
        $cp   = (int) ($this->cfg['codepage'] ?? 2);
        $line = str_repeat('-', $w);

        $out  = self::ESC . '@' . self::ESC . 't' . chr($cp);
        $out .= self::ESC . 'a' . "\x01";                    // centred
        if (!empty($t['brand'])) {
            $out .= self::ESC . '!' . "\x38" . $this->enc((string) $t['brand']) . self::LF;   // double + bold
            $out .= self::ESC . '!' . "\x00";
        }
        $out .= $line . self::LF;
        if (!empty($t['service'])) {
            $out .= self::ESC . '!' . "\x18" . $this->enc((string) $t['service']) . self::LF; // double height + bold
            $out .= self::ESC . '!' . "\x00";
        }
        $out .= self::LF;
        $out .= self::GS . '!' . "\x55";                      // 6x width, 6x height
        $out .= self::ESC . 'E' . "\x01" . $this->enc((string) ($t['number'] ?? '')) . self::LF;
        $out .= self::ESC . 'E' . "\x00" . self::GS . '!' . "\x00";
        $out .= self::LF;
        if (!empty($t['ahead'])) {
            $out .= self::ESC . '!' . "\x08" . $this->enc((string) $t['ahead']) . self::LF;
            $out .= self::ESC . '!' . "\x00";
        }
        if (!empty($t['wait'])) {
            $out .= self::ESC . '!' . "\x08" . $this->enc((string) $t['wait']) . self::LF;
            $out .= self::ESC . '!' . "\x00";
        }
        if (!empty($t['time'])) {
            $out .= $this->enc((string) $t['time']) . self::LF;
        }
        $out .= $line . self::LF;
        if (!empty($t['footer'])) {
            foreach (preg_split('/\R/', (string) $t['footer']) as $fl) {
                $out .= $this->enc($fl) . self::LF;
            }
        }
        $out .= str_repeat(self::LF, 4);
        $out .= self::GS . 'V' . "\x01";                     // partial cut

        $written = @fwrite($fp, $out);
        @fclose($fp);
        if ($written === false || $written < strlen($out)) {
            return ['ok' => false, 'error' => 'short_write'];
        }
        return ['ok' => true, 'bytes' => $written];
    }

    /** UTF-8 -> printer code page so accents (è, à, ò) print correctly. */
    private function enc(string $s): string
    {
        $cp = (int) ($this->cfg['codepage'] ?? 2);
        $target = $cp === 0 ? 'CP437' : 'CP850';
        $r = @iconv('UTF-8', $target . '//TRANSLIT//IGNORE', $s);
        return $r === false ? $s : $r;
    }
}
