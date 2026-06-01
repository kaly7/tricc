<?php
/**
 * Minimális PHP MQTT 3.1.1 kliens – agvmgr worker részére
 * Csak amit a worker igényel: connect, subscribe, publish, proc loop
 * Nincs külső függőség.
 */
class PhpMQTT {
    private string $host;
    private int    $port;
    private string $clientId;
    /** @var resource|null */
    private $sock      = null;
    private int    $msgId     = 1;
    private int    $keepalive;
    private int    $lastPing  = 0;
    private string $username  = '';
    private string $password  = '';
    /** @var callable|null  fn(string $topic, string $payload) */
    public  $callback = null;

    public function __construct(string $host, int $port, string $clientId, int $keepalive = 60) {
        $this->host      = $host;
        $this->port      = $port;
        $this->clientId  = $clientId;
        $this->keepalive = $keepalive;
    }

    public function setAuth(string $username, string $password): void {
        $this->username = $username;
        $this->password = $password;
    }

    public function connect(bool $clean = true): bool {
        $this->sock = @fsockopen($this->host, $this->port, $errno, $errstr, 10);
        if (!$this->sock) return false;

        // Blocking mode a handshake-hez
        stream_set_blocking($this->sock, true);
        stream_set_timeout($this->sock, 5);

        // CONNECT csomag összerakása
        $payload  = $this->sf($this->clientId);
        $flags    = $clean ? 0x02 : 0x00;
        if ($this->username !== '') {
            $flags   |= 0x80;
            $payload .= $this->sf($this->username);
            if ($this->password !== '') {
                $flags   |= 0x40;
                $payload .= $this->sf($this->password);
            }
        }
        $varHdr = $this->sf('MQTT') . chr(4) . chr($flags) . $this->u16($this->keepalive);
        $this->writeRaw(0x10, $varHdr . $payload);

        // CONNACK olvasása (mindig 4 bájt: 0x20 0x02 <flags> <rc>)
        $hdr = @fread($this->sock, 4);
        stream_set_blocking($this->sock, false);

        if (!$hdr || strlen($hdr) < 4 || (ord($hdr[0]) & 0xF0) !== 0x20) {
            fclose($this->sock); $this->sock = null; return false;
        }
        if (ord($hdr[3]) !== 0) {
            fclose($this->sock); $this->sock = null; return false;
        }
        $this->lastPing = time();
        return true;
    }

    /**
     * @param array<string,int> $topics  ['topic/path' => qos, ...]
     */
    public function subscribe(array $topics): void {
        $payload = $this->u16($this->msgId++);
        foreach ($topics as $topic => $qos) {
            $payload .= $this->sf($topic) . chr($qos & 0x03);
        }
        $this->writeRaw(0x82, $payload);
    }

    public function publish(string $topic, string $message, int $qos = 0, bool $retain = false): void {
        if (!$this->isConnected()) return;
        $flags   = ($retain ? 0x01 : 0x00) | (($qos & 0x03) << 1);
        $payload = $this->sf($topic);
        if ($qos > 0) $payload .= $this->u16($this->msgId++);
        $payload .= $message;
        $this->writeRaw(0x30 | $flags, $payload);
    }

    /**
     * Nem-blokkoló feldolgozó lépés: keepalive + egy bejövő csomag olvasása.
     * @return bool  false = kapcsolat megszakadt
     */
    public function proc(): bool {
        if (!$this->isConnected()) return false;

        // Keepalive PINGREQ
        if (time() - $this->lastPing >= $this->keepalive - 5) {
            $this->writeRaw(0xC0, '');
            $this->lastPing = time();
        }

        // Van-e adat? (10 ms timeout)
        $r = [$this->sock]; $w = $e = null;
        if (@stream_select($r, $w, $e, 0, 10000) < 1) return true;

        // Fixed header: típus bájt
        $b1 = @fread($this->sock, 1);
        if ($b1 === false || $b1 === '') return !feof($this->sock);

        // Remaining length (variable-length encoding)
        $len = $this->decVarInt();
        if ($len < 0) return true;

        $data = $len > 0 ? $this->readExact($len) : '';
        $type = ord($b1) & 0xF0;

        if ($type === 0x30 && strlen($data) >= 2) {       // PUBLISH
            $tlen  = (ord($data[0]) << 8) | ord($data[1]);
            $topic = substr($data, 2, $tlen);
            $off   = 2 + $tlen;
            $qos   = (ord($b1) >> 1) & 0x03;
            if ($qos > 0 && strlen($data) >= $off + 2) {
                $this->writeRaw(0x40, substr($data, $off, 2)); // PUBACK
                $off += 2;
            }
            if ($this->callback) {
                ($this->callback)($topic, substr($data, $off));
            }
        } elseif ($type === 0xD0) {                        // PINGRESP
            $this->lastPing = time();
        }
        return true;
    }

    public function disconnect(): void {
        if ($this->sock) {
            @$this->writeRaw(0xE0, '');
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    public function isConnected(): bool {
        return $this->sock !== null && !feof($this->sock);
    }

    // ── belső segédek ─────────────────────────────────────────────────────────

    private function writeRaw(int $cmd, string $payload): void {
        $n   = strlen($payload);
        $hdr = chr($cmd);
        // Variable-length encode
        do { $b = $n & 0x7F; $n >>= 7; if ($n) $b |= 0x80; $hdr .= chr($b); } while ($n);
        @fwrite($this->sock, $hdr . $payload);
    }

    private function decVarInt(): int {
        $mult = 1; $val = 0;
        for ($i = 0; $i < 4; $i++) {
            $b = @fread($this->sock, 1);
            if ($b === false || $b === '') return -1;
            $b    = ord($b);
            $val += ($b & 0x7F) * $mult;
            $mult <<= 7;
            if (!($b & 0x80)) return $val;
        }
        return -1;
    }

    private function readExact(int $n): string {
        $buf = ''; $rem = $n;
        $dl  = microtime(true) + 1.0;
        while ($rem > 0 && microtime(true) < $dl) {
            $c = @fread($this->sock, $rem);
            if ($c === false) break;
            if ($c === '') { usleep(500); continue; }
            $buf .= $c; $rem -= strlen($c);
        }
        return $buf;
    }

    /** MQTT string field: 2-byte length + data */
    private function sf(string $s): string { return $this->u16(strlen($s)) . $s; }
    /** Big-endian uint16 */
    private function u16(int $n): string   { return chr($n >> 8) . chr($n & 0xFF); }
}
